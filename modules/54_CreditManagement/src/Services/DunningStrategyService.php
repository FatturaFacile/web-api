<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Models\CreditCollectionCase;
use Modules\CreditManagement\Models\CreditDunningStep;
use Modules\CreditManagement\Models\CreditDunningStrategy;

/**
 * Dunning strategies. The steps are numbered by their position in the payload and their days after the due date never
 * go down, so a case moves through them in order. A company has one default strategy: the first one created, or the
 * one marked as default. Steps cannot be replaced while an open case already ran some of them.
 */
final readonly class DunningStrategyService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): CreditDunningStrategy
    {
        $this->assertSteps($data['steps']);

        return DB::transaction(function () use ($tenantId, $data): CreditDunningStrategy {
            $isFirst = ! CreditDunningStrategy::query()->where('tenant_id', $tenantId)->exists();
            $isDefault = $isFirst || (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                CreditDunningStrategy::query()->where('tenant_id', $tenantId)->update(['is_default' => false]);
            }

            $strategy = CreditDunningStrategy::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_default' => $isDefault,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->writeSteps($strategy, $data['steps']);

            return $strategy->load('steps');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CreditDunningStrategy $strategy, array $data): CreditDunningStrategy
    {
        if (isset($data['steps'])) {
            $this->assertSteps($data['steps']);

            if (CreditCollectionCase::query()->where('strategy_id', $strategy->id)->whereNotIn('status', CreditCollectionCase::ClosedStatuses)->where('current_step_order', '>', 0)->exists()) {
                throw ValidationException::withMessages(['steps' => [__('credit_errors.strategy.in_use')]]);
            }
        }

        return DB::transaction(function () use ($strategy, $data): CreditDunningStrategy {
            if ((bool) ($data['is_default'] ?? false)) {
                CreditDunningStrategy::query()->where('tenant_id', $strategy->tenant_id)->whereKeyNot($strategy->id)->update(['is_default' => false]);
            }

            if (($data['is_default'] ?? null) === false && $strategy->is_default) {
                throw ValidationException::withMessages(['is_default' => [__('credit_errors.strategy.default_required')]]);
            }

            $strategy->update(array_intersect_key($data, array_flip(['name', 'description', 'is_default', 'is_active'])));

            if (isset($data['steps'])) {
                $strategy->steps()->delete();
                $this->writeSteps($strategy, $data['steps']);
            }

            return $strategy->load('steps');
        });
    }

    public function delete(CreditDunningStrategy $strategy): void
    {
        if (CreditCollectionCase::query()->where('strategy_id', $strategy->id)->whereNotIn('status', CreditCollectionCase::ClosedStatuses)->exists()) {
            throw ValidationException::withMessages(['strategy' => [__('credit_errors.strategy.in_use')]]);
        }

        DB::transaction(function () use ($strategy): void {
            $strategy->steps()->delete();
            $strategy->delete();
        });
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function assertSteps(array $steps): void
    {
        $previous = -1;

        foreach ($steps as $index => $step) {
            if ((int) $step['days_after_due'] < $previous) {
                throw ValidationException::withMessages(["steps.{$index}.days_after_due" => [__('credit_errors.strategy.days_order')]]);
            }

            $previous = (int) $step['days_after_due'];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function writeSteps(CreditDunningStrategy $strategy, array $steps): void
    {
        foreach ($steps as $index => $step) {
            CreditDunningStep::create([
                'tenant_id' => $strategy->tenant_id,
                'strategy_id' => $strategy->id,
                'step_order' => $index + 1,
                'name' => $step['name'],
                'days_after_due' => $step['days_after_due'],
                'channel' => $step['channel'],
                'template_key' => $step['template_key'] ?? null,
                'fee_amount' => $step['fee_amount'] ?? '0.00',
                'escalates' => $step['escalates'] ?? false,
            ]);
        }
    }
}
