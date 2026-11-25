<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CpqConfigurator\Models\CpqConfiguredInstance;
use Modules\CpqConfigurator\Models\CpqModel;

/**
 * Confirms a configuration. The canonical selections are hashed (SHA-256); when the same model already has an instance
 * with that hash, its part number, item and BOM are reused and nothing is created. Otherwise a new smart part number is
 * issued from the per-model sequence, the resolved configuration is stored and the item and BOM are materialised.
 * The model row is locked for the duration, so two simultaneous confirmations of the same configuration end up with one
 * instance.
 */
final class ConfigurationConfirmationService
{
    public function __construct(
        private readonly ModelDefinitionLoader $loader,
        private readonly ConfigurationEvaluator $evaluator,
        private readonly PartNumberGenerator $partNumbers,
        private readonly BomMaterializer $materializer,
    ) {}

    /**
     * @param  array<string, mixed>  $selections
     * @return array{instance: CpqConfiguredInstance, reused: bool}
     */
    public function confirm(CpqModel $model, array $selections, float $quantity, float $discountPercent, ?string $customerReference, ?string $userId, ?string $locale = null): array
    {
        if ($model->status !== CpqModel::Published) {
            throw ValidationException::withMessages(['model' => ['CPQ_CONFIGURATOR.ERR.MODEL_NOT_PUBLISHED']]);
        }

        return DB::transaction(function () use ($model, $selections, $quantity, $discountPercent, $customerReference, $userId, $locale): array {
            /** @var CpqModel $locked */
            $locked = CpqModel::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $definition = $this->loader->load($locked);
            $evaluation = $this->evaluator->evaluate($definition, $selections, $quantity, $discountPercent, $locale);

            if (! $evaluation['complete']) {
                throw ValidationException::withMessages(['selections' => ['CPQ_CONFIGURATOR.ERR.CONFIGURATION_INCOMPLETE']]);
            }

            $existing = CpqConfiguredInstance::query()->where('model_id', $locked->id)->where('configuration_hash', $evaluation['configuration_hash'])->lockForUpdate()->first();

            if ($existing instanceof CpqConfiguredInstance) {
                $existing->forceFill(['reuse_count' => $existing->reuse_count + 1])->save();

                return ['instance' => $existing->refresh(), 'reused' => true];
            }

            $totals = $evaluation['totals'];
            $partNumber = $this->partNumbers->next($locked, $definition, $evaluation['values'], $evaluation['solution']['visible']);

            $instance = CpqConfiguredInstance::query()->create([
                'model_id' => $locked->id,
                'model_version' => $locked->version,
                'configuration_hash' => $evaluation['configuration_hash'],
                'part_number' => $partNumber,
                'configuration' => $evaluation['canonical'],
                'snapshot' => $this->snapshot($evaluation),
                'currency' => $locked->currency,
                'industrial_cost' => $totals['industrial_cost'],
                'list_price' => $totals['list_price'],
                'selling_price' => $totals['selling_price'],
                'margin_amount' => $totals['margin_amount'],
                'margin_percent' => $totals['margin_percent'],
                'lead_time_days' => $totals['lead_time_days'],
                'status' => CpqConfiguredInstance::Confirmed,
                'customer_reference' => $customerReference,
                'created_by' => $userId,
                'confirmed_at' => now(),
            ]);

            $master = $this->materializer->materialize($instance, $evaluation, (string) ($locked->name[$locale ?? 'en'] ?? $locked->code));
            $instance->forceFill(['mfg_item_id' => $master['item']->id, 'mfg_bom_id' => $master['bom']->id])->save();

            return ['instance' => $instance->refresh(), 'reused' => false];
        });
    }

    /**
     * What is kept of the evaluation: the resolved BOM and routing, the pricing breakdown, the totals, the automatic
     * inclusions and the layers of the preview, in the language of the confirmation.
     *
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function snapshot(array $evaluation): array
    {
        return array_intersect_key($evaluation, array_flip(['values', 'auto_included', 'bom', 'routing', 'pricing', 'totals', 'visual', 'characteristics', 'steps', 'configuration_hash', 'model']));
    }
}
