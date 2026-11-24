<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\Tenancy\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\DTOs\InventoryCountSessionDTO;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\CycleCountPlan;
use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;

/**
 * Runs cycle count plans: each run opens a count session for the items of the plan's ABC class that have stock in its
 * warehouse (every item when the plan has no class), freezing their stock if the plan says so, and schedules the next
 * run `interval_days` later (company calendar, TenantClock). A plan whose previous session is still open is not run
 * again: counts of the same items must not overlap.
 */
final readonly class CycleCountPlanService
{
    public function __construct(
        private InventoryCountService $counts,
        private TenantClock $clock
    ) {}

    /**
     * @throws StockRuleViolation when the plan is inactive, its previous session is still open or nothing is in scope
     */
    public function run(CycleCountPlan $plan, string $userId): InventoryCountSession
    {
        return DB::transaction(function () use ($plan, $userId): InventoryCountSession {
            /** @var CycleCountPlan $locked */
            $locked = CycleCountPlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw new StockRuleViolation('An inactive cycle count plan cannot be run.');
            }

            $open = InventoryCountSession::query()->where('cycle_count_plan_id', $locked->id)->whereIn('status', InventoryCountService::OPEN_STATUSES)->value('session_number');

            if ($open !== null) {
                throw new StockRuleViolation("The previous count {$open} of this plan is still open.");
            }

            $itemIds = StockQuant::query()
                ->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $locked->warehouse_id))
                ->whereIn('stock_item_id', StockItem::query()->where('is_active', true)->when($locked->abc_class !== null, static fn ($items) => $items->where('abc_class', $locked->abc_class))->select('id'))
                ->distinct()
                ->pluck('stock_item_id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

            if ($itemIds === []) {
                throw new StockRuleViolation('No item of this plan has stock in its warehouse: run the ABC classification first, or widen the plan.');
            }

            $today = $this->clock->today();
            $session = $this->counts->createSession(new InventoryCountSessionDTO(
                warehouseId: (string) $locked->warehouse_id,
                countType: 'cycle_count_abc',
                scheduledDate: $today->toDateString(),
                userId: $userId,
                notes: "Cycle count plan {$locked->name}".($locked->abc_class !== null ? " (class {$locked->abc_class})" : ''),
                zoneId: $locked->warehouse_zone_id !== null ? (string) $locked->warehouse_zone_id : null,
                freezesStock: (bool) $locked->freezes_stock,
                stockItemIds: $itemIds,
                planId: (string) $locked->id,
            ));

            $locked->update(['last_run_at' => Carbon::now(), 'next_run_on' => $today->addDays((int) $locked->interval_days)->toDateString()]);

            return $session;
        });
    }

    /**
     * Creates or updates one plan per ABC class for a warehouse (optionally one zone), with the cadence of the tenant's
     * InventoryCountPolicy: class A monthly, B quarterly, C semi-annual by default (any interval in days is allowed).
     * A plan that already exists keeps its next run date unless its interval changed; new plans are due at once.
     *
     * @return list<CycleCountPlan> the plans of the classes A, B, C
     */
    public function generateAbcPlans(string $warehouseId, string $userId, bool $freezesStock = false, ?string $zoneId = null): array
    {
        return DB::transaction(function () use ($warehouseId, $userId, $freezesStock, $zoneId): array {
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            $policy = InventoryCountPolicy::forTenant((string) $warehouse->tenant_id);
            $today = $this->clock->today();
            $plans = [];

            foreach (['A', 'B', 'C'] as $class) {
                $interval = $policy->intervalDaysFor($class);
                $existing = CycleCountPlan::query()->where('warehouse_id', $warehouseId)->where('abc_class', $class)->where('warehouse_zone_id', $zoneId)->first();

                if ($existing === null) {
                    $plans[] = CycleCountPlan::create([
                        'tenant_id' => $warehouse->tenant_id,
                        'warehouse_id' => $warehouseId,
                        'warehouse_zone_id' => $zoneId,
                        'name' => "ABC class {$class} cycle count",
                        'abc_class' => $class,
                        'interval_days' => $interval,
                        'freezes_stock' => $freezesStock,
                        'is_active' => true,
                        'next_run_on' => $today->toDateString(),
                        'created_by' => $userId,
                    ]);

                    continue;
                }

                $changed = (int) $existing->interval_days !== $interval;
                $existing->update([
                    'interval_days' => $interval,
                    'freezes_stock' => $freezesStock,
                    'is_active' => true,
                    'next_run_on' => $changed && $existing->last_run_at !== null
                        ? Carbon::parse($existing->last_run_at)->addDays($interval)->toDateString()
                        : ($existing->next_run_on?->toDateString() ?? $today->toDateString()),
                ]);
                $plans[] = $existing;
            }

            return $plans;
        });
    }

    /**
     * Runs every active plan of the current company that is due today; plans that cannot run are skipped.
     *
     * @return int the number of sessions opened
     */
    public function runDue(string $userId): int
    {
        $today = $this->clock->today()->toDateString();
        $opened = 0;

        foreach (CycleCountPlan::query()->where('is_active', true)->where(static fn ($due) => $due->whereNull('next_run_on')->orWhereDate('next_run_on', '<=', $today))->orderBy('id')->get() as $plan) {
            try {
                $this->run($plan, $userId);
                $opened++;
            } catch (StockRuleViolation) {
                continue;
            }
        }

        return $opened;
    }
}
