<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AnalyticalAccounting\Domain\Enums\CostElementType;
use Modules\AnalyticalAccounting\Domain\Enums\EntryType;
use Modules\AnalyticalAccounting\Domain\Services\StepDownApportionmentEngine;
use Modules\AnalyticalAccounting\Models\AnalyticalCenter;
use Modules\AnalyticalAccounting\Models\ApportionmentRun;
use Modules\AnalyticalAccounting\Models\ApportionmentRunAllocation;
use Modules\AnalyticalAccounting\Models\ApportionmentRunStep;
use Modules\AnalyticalAccounting\Models\ApportionmentSchedule;
use Modules\AnalyticalAccounting\Support\Money;

/**
 * Guided monthly step-down cycle: preview (a stored snapshot that writes nothing to the ledger), consolidate (atomic,
 * posts one STEP_DOWN_APPORTIONMENT entry per allocation and locks the period against a second run) and reverse
 * (compensating entries). The math lives in the pure StepDownApportionmentEngine; this class feeds it from the database.
 */
final class ApportionmentService
{
    public function __construct(
        private readonly StepDownApportionmentEngine $engine,
        private readonly CenterService $centers,
        private readonly AnalyticalPostingService $posting,
    ) {}

    public function preview(string $tenantId, string $scheduleId, string $periodKey, ?string $userId = null): ApportionmentRun
    {
        $schedule = $this->schedule($tenantId, $scheduleId);

        return DB::transaction(function () use ($tenantId, $schedule, $periodKey, $userId): ApportionmentRun {
            // A new preview replaces the previous drafts of the same schedule and period.
            ApportionmentRun::query()->where('tenant_id', $tenantId)->where('apportionment_schedule_id', $schedule->id)
                ->where('period_key', $periodKey)->where('status', 'PREVIEW')->get()->each->delete();

            return $this->storeSnapshot($tenantId, $schedule, $periodKey, 'PREVIEW', $userId);
        });
    }

    /**
     * Recomputes on the current ledger (so a stale preview cannot post old numbers), stores the snapshot and posts it.
     */
    public function consolidate(string $tenantId, string $runId, ?string $userId = null): ApportionmentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $userId): ApportionmentRun {
            $draft = ApportionmentRun::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($runId);
            if ($draft->status !== 'PREVIEW') {
                throw new InvalidArgumentException('Only a preview run can be consolidated.');
            }
            $exists = ApportionmentRun::query()->where('tenant_id', $tenantId)->where('period_key', $draft->period_key)
                ->where('status', 'CONSOLIDATED')->where('apportionment_schedule_id', $draft->apportionment_schedule_id)->lockForUpdate()->exists();
            if ($exists) {
                throw new InvalidArgumentException("Period {$draft->period_key} is already consolidated for this schedule: reverse it first.");
            }
            $schedule = $this->schedule($tenantId, (string) $draft->apportionment_schedule_id);
            $draft->delete();
            $run = $this->storeSnapshot($tenantId, $schedule, (string) $draft->period_key, 'CONSOLIDATED', $userId);
            if ($run->allocations()->count() === 0) {
                throw new InvalidArgumentException('Nothing to apportion for this period.');
            }

            foreach ($run->allocations()->get() as $allocation) {
                $entry = $this->postAllocation($tenantId, $run, $allocation, $userId);
                $allocation->forceFill(['analytical_entry_id' => $entry])->save();
            }
            $run->forceFill(['consolidated_at' => now(), 'consolidated_by' => $userId])->save();

            return $run->refresh();
        });
    }

    public function reverse(string $tenantId, string $runId, ?string $reason = null, ?string $userId = null): ApportionmentRun
    {
        return DB::transaction(function () use ($tenantId, $runId, $reason, $userId): ApportionmentRun {
            $run = ApportionmentRun::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'CONSOLIDATED') {
                throw new InvalidArgumentException('Only a consolidated run can be reversed.');
            }
            $this->posting->reverseRunEntries($run, $userId, $reason);
            $run->forceFill(['status' => 'REVERSED', 'reversed_at' => now(), 'reversed_by' => $userId, 'reversal_reason' => $reason])->save();

            return $run->refresh();
        });
    }

    /**
     * Pure computation for a schedule and period, nothing stored.
     *
     * @return array{steps: list<array<string, mixed>>, allocations: list<array<string, mixed>>, warnings: list<array<string, mixed>>, total_pool_cents: int, total_allocated_cents: int, unallocated_cents: int, final_balances: array<string, int>}
     */
    public function compute(string $tenantId, ApportionmentSchedule $schedule, string $periodKey): array
    {
        [$start, $end] = $this->bounds($periodKey);
        $steps = [];
        foreach ($schedule->steps()->get() as $step) {
            $steps[] = [
                'step_order' => (int) $step->step_order,
                'source_center_id' => (string) $step->source_center_id,
                'driver_type' => (string) $step->driver_type,
                'include_descendants' => (bool) $step->include_descendants,
                'fixed_weights' => $step->fixed_weights_json,
                'target_filter' => $step->target_filter_json,
                'driver_values' => $step->driver_type === 'FIXED_PERCENTAGE' ? [] : $this->driverValues($tenantId, $step->allocation_driver_id === null ? null : (string) $step->allocation_driver_id, (string) $step->driver_type, $periodKey, $start, $end),
            ];
        }

        return $this->engine->run($steps, $this->directCosts($tenantId, $periodKey), $this->centers->flatTree($tenantId));
    }

    /**
     * Direct cost of the period by center, in cents: cost lines posted by documents and manual entries only.
     *
     * @return array<string, int>
     */
    public function directCosts(string $tenantId, string $periodKey): array
    {
        $rows = DB::table('analytical_entry_lines as l')
            ->join('analytical_entries as e', 'e.id', '=', 'l.analytical_entry_id')
            ->where('l.tenant_id', $tenantId)->where('l.period_key', $periodKey)
            ->whereNull('l.deleted_at')->whereNull('e.deleted_at')
            ->where('e.is_reversed', false)
            ->whereNotIn('e.entry_type', [EntryType::REVERSAL->value, EntryType::STEP_DOWN_APPORTIONMENT->value])
            ->whereIn('l.cost_element_type', [CostElementType::VARIABLE_DIRECT->value, CostElementType::FIXED_DIRECT->value, CostElementType::INDIRECT_AUXILIARY->value])
            ->get(['l.cost_center_id', 'l.amount']);
        $out = [];
        foreach ($rows as $row) {
            if ($row->cost_center_id !== null) {
                $out[(string) $row->cost_center_id] = ($out[(string) $row->cost_center_id] ?? 0) + Money::toCents((string) $row->amount);
            }
        }

        return $out;
    }

    /**
     * Driver value by center: the stored value of the period (or the latest earlier one); REVENUE_VOLUME falls back to the
     * revenue posted on the center in the period when nothing is stored.
     *
     * @return array<string, string>
     */
    private function driverValues(string $tenantId, ?string $driverId, string $driverType, string $periodKey, Carbon $start, Carbon $end): array
    {
        if ($driverId === null) {
            $driverId = DB::table('analytical_allocation_drivers')->where('tenant_id', $tenantId)->where('driver_type', $driverType)
                ->where('is_active', true)->whereNull('deleted_at')->orderBy('is_system', 'desc')->value('id');
        }
        $values = [];
        if ($driverId !== null) {
            $rows = DB::table('analytical_driver_values')->where('tenant_id', $tenantId)->where('allocation_driver_id', $driverId)
                ->where('period_key', '<=', $periodKey)->whereNull('deleted_at')->orderBy('period_key')->get(['analytical_center_id', 'value']);
            foreach ($rows as $row) {
                $values[(string) $row->analytical_center_id] = (string) $row->value;
            }
        }
        if ($values === [] && $driverType === 'REVENUE_VOLUME') {
            $rows = DB::table('analytical_entry_lines as l')->join('analytical_entries as e', 'e.id', '=', 'l.analytical_entry_id')
                ->where('l.tenant_id', $tenantId)->where('l.period_key', $periodKey)->where('l.cost_element_type', CostElementType::REVENUE->value)
                ->whereNull('l.deleted_at')->where('e.is_reversed', false)->where('e.entry_type', '!=', EntryType::REVERSAL->value)
                ->get(['l.cost_center_id', 'l.profit_center_id', 'l.amount']);
            foreach ($rows as $row) {
                $center = (string) ($row->profit_center_id ?? $row->cost_center_id ?? '');
                if ($center !== '') {
                    $values[$center] = bcadd($values[$center] ?? '0', (string) $row->amount, 4);
                }
            }
        }

        return $values;
    }

    /**
     * @param  'PREVIEW'|'CONSOLIDATED'  $status
     */
    private function storeSnapshot(string $tenantId, ApportionmentSchedule $schedule, string $periodKey, string $status, ?string $userId): ApportionmentRun
    {
        [$start, $end] = $this->bounds($periodKey);
        $result = $this->compute($tenantId, $schedule, $periodKey);
        $run = ApportionmentRun::create([
            'tenant_id' => $tenantId, 'apportionment_schedule_id' => $schedule->id, 'period_key' => $periodKey,
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'status' => $status,
            'total_pool' => Money::fromCents($result['total_pool_cents']), 'total_allocated' => Money::fromCents($result['total_allocated_cents']),
            'total_unallocated' => Money::fromCents($result['unallocated_cents']), 'warnings' => $result['warnings'], 'created_by' => $userId,
        ]);
        foreach ($result['steps'] as $step) {
            ApportionmentRunStep::create([
                'tenant_id' => $tenantId, 'apportionment_run_id' => $run->id, 'step_order' => $step['step_order'],
                'source_center_id' => $step['source_center_id'], 'driver_type' => $step['driver_type'], 'status' => $step['status'],
                'pool_amount' => Money::fromCents($step['pool_cents']), 'allocated_amount' => Money::fromCents($step['allocated_cents']),
                'driver_total' => $step['driver_total'], 'warnings' => $step['warnings'], 'created_by' => $userId,
            ]);
        }
        foreach ($result['allocations'] as $allocation) {
            ApportionmentRunAllocation::create([
                'tenant_id' => $tenantId, 'apportionment_run_id' => $run->id, 'step_order' => $allocation['step_order'],
                'source_center_id' => $allocation['source_center_id'], 'target_center_id' => $allocation['target_center_id'],
                'driver_type' => $allocation['driver_type'], 'driver_value' => $allocation['driver_value'], 'driver_total' => $allocation['driver_total'],
                'share_ratio' => $allocation['share_ratio'], 'allocated_amount' => Money::fromCents($allocation['allocated_cents']),
                'rounding_adjustment' => Money::fromCents($allocation['rounding_adjustment_cents']), 'created_by' => $userId,
            ]);
        }

        return $run->refresh();
    }

    private function postAllocation(string $tenantId, ApportionmentRun $run, ApportionmentRunAllocation $allocation, ?string $userId): string
    {
        $amount = (string) $allocation->allocated_amount;
        $out = [$this->dimensionFor($tenantId, (string) $allocation->source_center_id) => (string) $allocation->source_center_id, 'cost_element_type' => CostElementType::INDIRECT_AUXILIARY->value, 'amount' => bcmul($amount, '-1', 4), 'description' => 'Step-down apportionment out (step '.$allocation->step_order.')'];
        $in = [$this->dimensionFor($tenantId, (string) $allocation->target_center_id) => (string) $allocation->target_center_id, 'cost_element_type' => CostElementType::INDIRECT_AUXILIARY->value, 'amount' => $amount, 'description' => 'Step-down apportionment in (step '.$allocation->step_order.')'];
        $entry = $this->posting->post($tenantId, [
            'entry_type' => EntryType::STEP_DOWN_APPORTIONMENT->value,
            'posting_date' => (string) $run->period_end->toDateString(),
            'description' => 'Step-down apportionment '.$run->period_key.' step '.$allocation->step_order,
            'source_type' => 'apportionment_run', 'source_id' => $run->id, 'apportionment_run_id' => $run->id,
        ], [$out, $in], $userId, false);
        DB::table('analytical_entry_lines')->where('analytical_entry_id', $entry->id)->update(['derivation_source' => 'APPORTIONMENT', 'apportionment_step_id' => $allocation->id]);

        return (string) $entry->id;
    }

    /**
     * Profit centers receive on the profit center dimension, every other center on the cost center dimension.
     */
    private function dimensionFor(string $tenantId, string $centerId): string
    {
        $type = AnalyticalCenter::query()->where('tenant_id', $tenantId)->whereKey($centerId)->value('center_type');

        return $type === 'PROFIT_CENTER' ? 'profit_center_id' : 'cost_center_id';
    }

    private function schedule(string $tenantId, string $scheduleId): ApportionmentSchedule
    {
        return ApportionmentSchedule::query()->where('tenant_id', $tenantId)->findOrFail($scheduleId);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function bounds(string $periodKey): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodKey) !== 1) {
            throw new InvalidArgumentException('The period key must look like YYYY-MM.');
        }
        $start = Carbon::createFromFormat('Y-m-d', $periodKey.'-01')->startOfDay();

        return [$start, $start->copy()->endOfMonth()->startOfDay()];
    }
}
