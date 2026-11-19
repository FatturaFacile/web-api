<?php

declare(strict_types=1);

namespace App\Services\RecurringContracts;

use App\Models\RecurringContracts\ContractIndexationClause;
use App\Models\RecurringContracts\ContractIndexationLog;
use App\Models\RecurringContracts\PriceIndexRate;
use App\Models\RecurringContracts\RecurringContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IndexationAdjustmentEngine
{
    /**
     * Calculate percentage delta between base and target index.
     */
    public function calculateDeltaPercentage(string $base, string $target): string
    {
        if (bccomp($base, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Base index value must be greater than zero.');
        }

        $diff = bcsub($target, $base, 6);

        return bcmul(bcdiv($diff, $base, 6), '100', 4);
    }

    /**
     * Apply clause modifiers (pass-through, floor, cap) to a raw delta.
     */
    public function applyClauseModifiers(
        string $rawDelta,
        string $passThroughPercent = '100.00',
        ?string $floorPercent = null,
        ?string $capPercent = null
    ): string {
        $applied = bcmul($rawDelta, bcdiv($passThroughPercent, '100', 4), 4);

        if ($floorPercent !== null && bccomp($applied, $floorPercent, 4) < 0) {
            $applied = bcadd($floorPercent, '0.0000', 4);
        }

        if ($capPercent !== null && bccomp($applied, $capPercent, 4) > 0) {
            $applied = bcadd($capPercent, '0.0000', 4);
        }

        return $applied;
    }

    /**
     * Compute indexation delta for a clause and target index rate.
     *
     * @return array{
     *   raw_delta_percent: string,
     *   applied_delta_percent: string,
     *   old_base: string,
     *   target_rate: string,
     * }
     */
    public function computeDelta(ContractIndexationClause $clause, PriceIndexRate $targetRate): array
    {
        $base = (string) $clause->base_index_value;
        $target = (string) $targetRate->index_value;

        if (bccomp($base, '0.0000', 4) <= 0) {
            throw new InvalidArgumentException('Base index value must be greater than zero.');
        }

        // raw_delta_percent = ((target - base) / base) * 100
        $diff = bcsub($target, $base, 6);
        $rawDelta = bcmul(bcdiv($diff, $base, 6), '100', 4);

        // pass_through_percent (e.g. 100.00 or 75.00)
        $passThrough = (string) ($clause->pass_through_percent ?? '100.00');
        $applied = bcmul($rawDelta, bcdiv($passThrough, '100', 4), 4);

        // floor clamping
        if ($clause->floor_percent !== null) {
            $floor = (string) $clause->floor_percent;
            if (bccomp($applied, $floor, 4) < 0) {
                $applied = $floor;
            }
        }

        // cap clamping
        if ($clause->cap_percent !== null) {
            $cap = (string) $clause->cap_percent;
            if (bccomp($applied, $cap, 4) > 0) {
                $applied = $cap;
            }
        }

        return [
            'raw_delta_percent' => $rawDelta,
            'applied_delta_percent' => $applied,
            'old_base' => $base,
            'target_rate' => $target,
        ];
    }

    /**
     * Apply indexation adjustment to a contract atomically.
     */
    public function applyIndexation(
        RecurringContract $contract,
        PriceIndexRate $targetRate,
        ?string $userId = null
    ): ContractIndexationLog {
        return DB::transaction(function () use ($contract, $targetRate, $userId): ContractIndexationLog {
            $clause = $contract->indexationClause;
            if (! $clause) {
                throw new InvalidArgumentException("Contract {$contract->contract_number} has no active indexation clause.");
            }

            if ($clause->index_type !== $targetRate->index_type) {
                throw new InvalidArgumentException("Index type mismatch: clause uses {$clause->index_type}, rate provides {$targetRate->index_type}.");
            }

            $delta = $this->computeDelta($clause, $targetRate);
            $appliedDeltaPercent = $delta['applied_delta_percent'];

            $oldRecurringTotal = $contract->calculateTotal();

            // Adjustment factor = 1 + (applied_delta_percent / 100)
            $factor = bcadd('1.0000', bcdiv($appliedDeltaPercent, '100', 6), 6);

            // Update all lines unit_price
            foreach ($contract->lines as $line) {
                $oldPrice = (string) $line->unit_price;
                $newPrice = bcmul($oldPrice, $factor, 4);
                $line->update(['unit_price' => $newPrice]);
            }

            $contract->refresh();
            $newRecurringTotal = $contract->calculateTotal();

            // Advance clause
            $oldPeriod = $clause->base_index_period;
            $newPeriod = $targetRate->period;

            $nextAdjustment = CarbonImmutable::parse($clause->next_adjustment_date)
                ->addMonthsNoOverflow($clause->adjustment_frequency_months);

            $clause->update([
                'base_index_value' => $targetRate->index_value,
                'base_index_period' => $newPeriod,
                'next_adjustment_date' => $nextAdjustment->toDateString(),
                'last_adjusted_at' => now(),
            ]);

            // Create indexation log
            return ContractIndexationLog::create([
                'tenant_id' => $contract->tenant_id,
                'recurring_contract_id' => $contract->id,
                'clause_id' => $clause->id,
                'period_from' => $oldPeriod,
                'period_to' => $newPeriod,
                'old_index_value' => $delta['old_base'],
                'new_index_value' => $delta['target_rate'],
                'raw_delta_percent' => $delta['raw_delta_percent'],
                'applied_delta_percent' => $appliedDeltaPercent,
                'old_recurring_total' => $oldRecurringTotal,
                'new_recurring_total' => $newRecurringTotal,
                'applied_at' => now(),
                'applied_by_user_id' => $userId,
            ]);
        });
    }
}
