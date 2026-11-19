<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use App\Actions\Sales\SaveSalesDocument;
use App\Models\Quote;
use App\Models\User;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ProductConfiguration;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Evaluation, saving and conversion of configurations.
 *
 * evaluate() validates a selection and prices it without saving anything (the live feedback of the guided screen).
 * A saved configuration moves draft -> validated -> (pending_approval -> approved | rejected) -> converted. The margin
 * guard flags a configuration whose margin is below the minimum of its model and then requires a discount approval by
 * someone with the right to approve before it can become a quote. Converting re-evaluates the configuration with the
 * current prices, writes one quote line per resolved component and freezes a snapshot of the model version, the
 * rules and the prices used: later price or rule changes never touch the quote.
 */
final readonly class ConfigurationService
{
    public function __construct(
        private ConfigurationEvaluator $evaluator,
        private ConfigurationPricer $pricer,
        private SaveSalesDocument $quotes,
        private TenantContext $tenantContext,
        private TenantClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  selections, quantity, contact_id, price_list_id, discount_percent, date, apply_defaults
     * @return array{valid: bool, violations: list<array<string, mixed>>, selections: list<array<string, mixed>>, applied_defaults: list<array<string, mixed>>, pricing: array<string, mixed>}
     */
    public function evaluate(ConfigurableModel $model, array $input): array
    {
        $model->unsetRelations();
        $definition = ModelDefinition::from($model);
        $result = $this->evaluator->evaluate($definition, (array) ($input['selections'] ?? []), (bool) ($input['apply_defaults'] ?? true));
        $pricing = $this->pricer->price($definition, $result, $this->context($input));

        return [
            'valid' => $result['violations'] === [],
            'violations' => $result['violations'],
            'selections' => $result['selections'],
            'applied_defaults' => $result['applied_defaults'],
            'pricing' => $pricing,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ConfigurableModel $model, array $data, User $user): ProductConfiguration
    {
        if ($model->status !== ConfigurableModel::Published) {
            throw ValidationException::withMessages(['model_id' => ['Only a published model can be configured.']]);
        }

        return DB::transaction(function () use ($model, $data, $user): ProductConfiguration {
            $configuration = new ProductConfiguration;
            $configuration->forceFill([
                'model_id' => $model->id,
                'number' => $this->nextNumber(),
                'status' => ProductConfiguration::Draft,
                'currency' => $model->currency,
                'created_by' => $user->id,
            ]);

            return $this->applyEvaluation($configuration, $model, $data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ProductConfiguration $configuration, array $data): ProductConfiguration
    {
        $this->assertOpen($configuration);

        if ($configuration->status === ProductConfiguration::PendingApproval) {
            throw ValidationException::withMessages(['status' => ['The configuration is waiting for approval: it cannot be changed.']]);
        }

        return DB::transaction(function () use ($configuration, $data): ProductConfiguration {
            $model = ConfigurableModel::query()->findOrFail($configuration->model_id);

            $merged = $data + [
                'selections' => $configuration->selections,
                'quantity' => $configuration->quantity,
                'discount_percent' => $configuration->discount_percent,
                'contact_id' => $configuration->contact_id,
                'price_list_id' => $configuration->price_list_id,
            ];

            return $this->applyEvaluation($configuration, $model, $merged);
        });
    }

    public function delete(ProductConfiguration $configuration): void
    {
        if ($configuration->status !== ProductConfiguration::Draft && $configuration->status !== ProductConfiguration::Rejected) {
            throw ValidationException::withMessages(['status' => ['Only a draft or rejected configuration can be deleted.']]);
        }

        $configuration->forceDelete();
    }

    /**
     * Evaluates the stored selections: a valid configuration becomes validated, an invalid one stays a draft. The
     * violations are returned either way.
     *
     * @return array{valid: bool, violations: list<array<string, mixed>>, selections: list<array<string, mixed>>, applied_defaults: list<array<string, mixed>>, pricing: array<string, mixed>}
     */
    public function validate(ProductConfiguration $configuration): array
    {
        $this->assertOpen($configuration);

        if (! in_array($configuration->status, [ProductConfiguration::Draft, ProductConfiguration::Validated], true)) {
            throw ValidationException::withMessages(['status' => ['Only a draft or validated configuration can be validated: change its selections to start again.']]);
        }

        return DB::transaction(function () use ($configuration): array {
            $model = ConfigurableModel::query()->findOrFail($configuration->model_id);
            $evaluation = $this->evaluate($model, $this->inputOf($configuration) + ['apply_defaults' => false]);
            $this->storeEvaluation($configuration, $model, $evaluation);

            if ($evaluation['valid']) {
                $this->moveTo($configuration, ProductConfiguration::Validated, ['validated_at' => now()]);
            } else {
                $this->moveTo($configuration, ProductConfiguration::Draft, ['validated_at' => null]);
            }

            return $evaluation;
        });
    }

    public function requestApproval(ProductConfiguration $configuration, User $user): ProductConfiguration
    {
        $this->assertOpen($configuration);

        if ($configuration->status !== ProductConfiguration::Validated) {
            throw ValidationException::withMessages(['status' => ['Only a validated configuration can ask for approval.']]);
        }

        if (! $configuration->requires_approval) {
            throw ValidationException::withMessages(['status' => ['The margin is within the limit: no approval is needed.']]);
        }

        $this->moveTo($configuration, ProductConfiguration::PendingApproval, ['approval_requested_by' => $user->id, 'approval_requested_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_notes' => null]);

        return $configuration->refresh();
    }

    public function decide(ProductConfiguration $configuration, User $user, bool $approve, ?string $notes): ProductConfiguration
    {
        if ($configuration->status !== ProductConfiguration::PendingApproval) {
            throw ValidationException::withMessages(['status' => ['Only a configuration waiting for approval can be decided.']]);
        }

        $this->moveTo($configuration, $approve ? ProductConfiguration::Approved : ProductConfiguration::Rejected, ['decided_by' => $user->id, 'decided_at' => now(), 'decision_notes' => $notes]);

        return $configuration->refresh();
    }

    /**
     * Turns the configuration into a quote with one line per resolved component.
     *
     * @param  array{date?: string|null, valid_until?: string|null, notes?: string|null}  $options
     */
    public function convertToQuote(ProductConfiguration $configuration, User $user, array $options = []): ProductConfiguration
    {
        $this->assertOpen($configuration);

        if (! in_array($configuration->status, [ProductConfiguration::Validated, ProductConfiguration::Approved], true)) {
            throw ValidationException::withMessages(['status' => ['Validate the configuration before turning it into a quote.']]);
        }

        if ($configuration->contact_id === null) {
            throw ValidationException::withMessages(['contact_id' => ['Choose the customer before turning the configuration into a quote.']]);
        }

        return DB::transaction(function () use ($configuration, $user, $options): ProductConfiguration {
            $model = ConfigurableModel::query()->findOrFail($configuration->model_id);
            $evaluation = $this->evaluate($model, $this->inputOf($configuration) + ['apply_defaults' => false]);

            if (! $evaluation['valid']) {
                throw ValidationException::withMessages(['selections' => array_column($evaluation['violations'], 'message')]);
            }

            $pricing = $evaluation['pricing'];
            $this->assertApprovalHolds($configuration, $pricing);

            $date = $options['date'] ?? $this->clock->today()->toDateString();

            if (($options['valid_until'] ?? null) !== null && $options['valid_until'] < $date) {
                throw ValidationException::withMessages(['valid_until' => ['The quote cannot expire before its date.']]);
            }

            $quote = $this->quotes->create(Quote::class, 'quote', 'quote_number', [
                'contact_id' => $configuration->contact_id,
                'date' => $date,
                'valid_until' => $options['valid_until'] ?? null,
                'currency' => $pricing['currency'],
                'notes' => $options['notes'] ?? null,
                'custom_text' => $this->summary($model, $configuration, $evaluation),
                'items' => array_map(static fn (array $line): array => [
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'discount_percentage' => $line['discount_percentage'],
                ], $pricing['lines']),
            ]);

            $this->storeEvaluation($configuration, $model, $evaluation);
            $configuration->forceFill([
                'quote_id' => $quote->getKey(),
                'converted_at' => now(),
                'snapshot' => $this->snapshot($model, $configuration, $evaluation, $user),
            ]);
            $this->moveTo($configuration, ProductConfiguration::Converted);

            return $configuration->refresh();
        });
    }

    /**
     * The approval covers the numbers that were approved: if the prices moved since, the configuration must be
     * validated again, and a margin that fell below the limit needs its own approval.
     *
     * @param  array<string, mixed>  $pricing
     */
    private function assertApprovalHolds(ProductConfiguration $configuration, array $pricing): void
    {
        $priceMoved = Decimal::compare((string) $pricing['total_net'], $configuration->total_net) !== 0;

        if ($priceMoved) {
            throw ValidationException::withMessages(['status' => ['The prices changed since the configuration was validated: validate it again.']]);
        }

        if ($pricing['requires_approval'] && $configuration->status !== ProductConfiguration::Approved) {
            throw ValidationException::withMessages(['status' => ['The margin is below the minimum: the discount must be approved first.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyEvaluation(ProductConfiguration $configuration, ConfigurableModel $model, array $data): ProductConfiguration
    {
        $evaluation = $this->evaluate($model, $data + ['apply_defaults' => true]);

        $configuration->forceFill([
            'name' => array_key_exists('name', $data) ? $data['name'] : $configuration->name,
            'contact_id' => $data['contact_id'] ?? null,
            'price_list_id' => $data['price_list_id'] ?? null,
            'quantity' => $data['quantity'] ?? '1',
            'discount_percent' => $data['discount_percent'] ?? '0',
            'status' => ProductConfiguration::Draft,
            'validated_at' => null,
            'approval_requested_by' => null,
            'approval_requested_at' => null,
            'decided_by' => null,
            'decided_at' => null,
            'decision_notes' => null,
        ]);
        $this->storeEvaluation($configuration, $model, $evaluation);
        $configuration->save();

        return $configuration->refresh();
    }

    /**
     * @param  array{selections: list<array<string, mixed>>, pricing: array<string, mixed>}  $evaluation
     */
    private function storeEvaluation(ProductConfiguration $configuration, ConfigurableModel $model, array $evaluation): void
    {
        $pricing = $evaluation['pricing'];
        $configuration->forceFill([
            'selections' => $evaluation['selections'],
            'pricing' => $pricing,
            'total_net' => $pricing['total_net'],
            'total_cost' => $pricing['total_cost'],
            'margin_percent' => $pricing['margin_percent'],
            'min_margin_percent' => $pricing['min_margin_percent'],
            'requires_approval' => $pricing['requires_approval'],
            'model_version' => $model->version,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function moveTo(ProductConfiguration $configuration, string $status, array $attributes = []): void
    {
        if ($configuration->status !== $status && ! $configuration->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => ["A {$configuration->status} configuration cannot become {$status}."]]);
        }

        $configuration->forceFill($attributes + ['status' => $status])->save();
    }

    private function assertOpen(ProductConfiguration $configuration): void
    {
        if ($configuration->isClosed()) {
            throw ValidationException::withMessages(['status' => ['The configuration became a quote and cannot be changed.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inputOf(ProductConfiguration $configuration): array
    {
        return [
            'selections' => $configuration->selections ?? [],
            'quantity' => $configuration->quantity,
            'discount_percent' => $configuration->discount_percent,
            'contact_id' => $configuration->contact_id,
            'price_list_id' => $configuration->price_list_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{quantity: string, contact_id: string|null, price_list_id: string|null, date: string, discount_percent: string}
     */
    private function context(array $input): array
    {
        return [
            'quantity' => (string) ($input['quantity'] ?? '1'),
            'contact_id' => $input['contact_id'] ?? null,
            'price_list_id' => $input['price_list_id'] ?? null,
            'date' => (string) ($input['date'] ?? $this->clock->today()->toDateString()),
            'discount_percent' => (string) ($input['discount_percent'] ?? '0'),
        ];
    }

    /**
     * What the quote tells the customer about the configuration: the model and one line per answered group.
     *
     * @param  array{selections: list<array<string, mixed>>}  $evaluation
     */
    private function summary(ConfigurableModel $model, ProductConfiguration $configuration, array $evaluation): string
    {
        $definition = ModelDefinition::from($model);
        $lines = ["{$model->name} ({$model->code}) - configuration {$configuration->number}"];

        foreach ($evaluation['selections'] as $selection) {
            $group = $definition->groups[$selection['group']] ?? null;

            if ($group === null) {
                continue;
            }

            if ($group->type === ConfiguratorOptionGroup::Range) {
                $answer = $selection['value'] === null ? '' : ConfiguratorMath::trim($selection['value']).($group->unit !== null ? ' '.$group->unit : '');
            } else {
                $answer = implode(', ', array_map(static fn (string $code): string => $definition->options[$code]->name ?? $code, $selection['options']));
            }

            if ($answer !== '') {
                $lines[] = "{$group->name}: {$answer}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The versions and prices behind the quote, stored once and never rewritten.
     *
     * @param  array{selections: list<array<string, mixed>>, pricing: array<string, mixed>}  $evaluation
     * @return array<string, mixed>
     */
    private function snapshot(ConfigurableModel $model, ProductConfiguration $configuration, array $evaluation, User $user): array
    {
        $definition = ModelDefinition::from($model);
        $chosen = [];

        foreach ($evaluation['selections'] as $selection) {
            foreach ($selection['options'] as $code) {
                $option = $definition->options[$code] ?? null;

                if ($option !== null) {
                    $chosen[] = ['code' => $code, 'name' => $option->name, 'price_delta' => $option->price_delta, 'price_multiplier' => $option->price_multiplier, 'cost' => $option->cost, 'product_id' => $option->product_id, 'updated_at' => $option->updated_at?->toIso8601String()];
                }
            }
        }

        $rules = array_map(static fn ($rule): array => [
            'id' => $rule->id, 'name' => $rule->name, 'type' => $rule->type, 'match' => $rule->match, 'conditions' => $rule->conditions, 'target_options' => $rule->target_options,
            'price_effect_type' => $rule->price_effect_type, 'price_effect_value' => $rule->price_effect_value, 'priority' => $rule->priority, 'updated_at' => $rule->updated_at?->toIso8601String(),
        ], $definition->rules);

        return [
            'model' => ['id' => $model->id, 'code' => $model->code, 'name' => $model->name, 'version' => $model->version, 'base_price' => $model->base_price, 'base_cost' => $model->base_cost, 'tax_rate' => $model->tax_rate, 'volume_breaks' => $model->volume_breaks, 'min_margin_percent' => $model->min_margin_percent],
            'options' => $chosen,
            'rules' => $rules,
            'rules_hash' => hash('sha256', (string) json_encode($rules)),
            'context' => ['quantity' => $configuration->quantity, 'contact_id' => $configuration->contact_id, 'price_list_id' => $configuration->price_list_id, 'discount_percent' => $configuration->discount_percent],
            'prices' => $evaluation['pricing'],
            'converted_by' => $user->id,
            'converted_at' => now()->toIso8601String(),
        ];
    }

    private function nextNumber(): string
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $year = now()->format('Y');

        TransactionLock::acquire("cfg_configurations:{$tenantId}");

        $last = DB::table('cfg_configurations')->where('tenant_id', $tenantId)->where('number', 'like', "CFG-{$year}-%")->max('number');

        return sprintf('CFG-%s-%04d', $year, $last === null ? 1 : ((int) substr((string) $last, -4)) + 1);
    }
}
