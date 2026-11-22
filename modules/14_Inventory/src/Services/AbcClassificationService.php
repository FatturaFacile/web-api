<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\InventoryAbcOverride;
use Modules\Inventory\Models\InventoryAbcRun;
use Modules\Inventory\Models\InventoryAbcRunItem;
use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * ABC classification of the stock items by consumption value (Pareto analysis, see AbcParetoClassifier): items are
 * ranked by the value of the stock that left the warehouses in the period, and the items making up the first `a_share`
 * percent of the cumulative value are class A, those up to `b_share` percent class B, the rest (and the active items
 * without consumption) class C. The shares and the window are parameters of each run, defaulting to the tenant's
 * InventoryCountPolicy (80/95 over 365 days when it has none).
 *
 * Consumption is every movement out of stock (source bin, no destination) except corrections and technical movements:
 * adjustments, scrap and the settlement of stock sold beyond availability (the sale itself was already counted).
 * The class is stored on the item and drives the cycle count plans. Two cases are special:
 * - an item whose class was set by hand (`abc_class_locked`, see override) keeps it; the run records the override;
 * - an item created inside the window with no consumption yet is a new item and gets the policy's `new_item_class`.
 * Every run can be stored (run) with its ranked items: the historical snapshot of the classification.
 */
final readonly class AbcClassificationService
{
    /** Movement types that take stock out without being consumption. */
    public const array NON_CONSUMPTION_TYPES = [
        'adjustment', 'adjustment_out', 'ADJUSTMENT_OUT', 'scrap_loss', 'SCRAP', 'scrap', ProductStockLedger::SETTLEMENT_MOVEMENT_TYPE,
    ];

    public function __construct(private TenantContext $tenants) {}

    /**
     * @return list<array{stock_item_id: string, sku: string, name: string, consumption_value: string, cumulative_share: string, abc_class: string, rank: int, previous_class: ?string, is_manual_override: bool, is_new_item: bool}>
     */
    public function classify(int $periodDays, string $aShare = '80', string $bShare = '95', bool $persist = true, ?string $warehouseId = null): array
    {
        $rows = $this->rank($periodDays, $aShare, $bShare, $warehouseId);

        if ($persist) {
            $this->apply($rows);
        }

        return $rows;
    }

    /**
     * Classifies with the tenant policy's defaults for whatever is not given, and stores the run with its ranked items.
     */
    public function run(?int $periodDays = null, ?string $aShare = null, ?string $bShare = null, ?string $warehouseId = null, ?string $userId = null, bool $apply = true): InventoryAbcRun
    {
        $policy = InventoryCountPolicy::forTenant($this->tenantId());
        $periodDays ??= $policy->abc_period_days;
        $aShare ??= (string) $policy->abc_a_share;
        $bShare ??= (string) $policy->abc_b_share;

        if (Quantity::compare($aShare, $bShare) >= 0 || ! Quantity::isPositive($aShare) || Quantity::compare($bShare, '100') > 0) {
            throw new StockRuleViolation('The ABC shares must satisfy 0 < A share < B share <= 100.');
        }

        $rows = $this->rank($periodDays, $aShare, $bShare, $warehouseId);

        return DB::transaction(function () use ($rows, $periodDays, $aShare, $bShare, $warehouseId, $userId, $apply): InventoryAbcRun {
            $run = InventoryAbcRun::create([
                'tenant_id' => $this->tenantId(),
                'warehouse_id' => $warehouseId,
                'period_days' => $periodDays,
                'a_share' => $aShare,
                'b_share' => $bShare,
                'period_start' => Carbon::now()->subDays($periodDays),
                'period_end' => Carbon::now(),
                'total_consumption_value' => array_reduce($rows, static fn (string $sum, array $row): string => Quantity::add($sum, $row['consumption_value'], 2), '0.00'),
                'item_count' => count($rows),
                'class_a_count' => count(array_filter($rows, static fn (array $row): bool => $row['abc_class'] === 'A')),
                'class_b_count' => count(array_filter($rows, static fn (array $row): bool => $row['abc_class'] === 'B')),
                'class_c_count' => count(array_filter($rows, static fn (array $row): bool => $row['abc_class'] === 'C')),
                'applied' => $apply,
                'run_by' => $userId,
                'created_by' => $userId,
            ]);

            foreach ($rows as $row) {
                InventoryAbcRunItem::create([
                    'tenant_id' => $run->tenant_id,
                    'inventory_abc_run_id' => $run->id,
                    'stock_item_id' => $row['stock_item_id'],
                    'rank' => $row['rank'],
                    'consumption_value' => $row['consumption_value'],
                    'cumulative_share' => $row['cumulative_share'],
                    'abc_class' => $row['abc_class'],
                    'previous_class' => $row['previous_class'],
                    'is_manual_override' => $row['is_manual_override'],
                    'is_new_item' => $row['is_new_item'],
                    'created_by' => $userId,
                ]);
            }

            if ($apply) {
                $this->apply($rows);
            }

            return $run;
        });
    }

    /**
     * Sets the class of an item by hand (it keeps it through later runs) or, with a null class, hands it back to the
     * classification. Every change is kept in the override audit with its reason.
     *
     * @throws StockRuleViolation when a class is given without a reason
     */
    public function override(StockItem $item, ?string $class, string $reason, string $userId): InventoryAbcOverride
    {
        if (trim($reason) === '') {
            throw new StockRuleViolation('A manual ABC override needs a reason.');
        }

        return DB::transaction(function () use ($item, $class, $reason, $userId): InventoryAbcOverride {
            $audit = InventoryAbcOverride::create([
                'tenant_id' => $item->tenant_id,
                'stock_item_id' => $item->id,
                'previous_class' => $item->abc_class,
                'new_class' => $class,
                'reason' => $reason,
                'set_by' => $userId,
                'set_at' => Carbon::now(),
                'created_by' => $userId,
            ]);

            $item->update($class === null
                ? ['abc_class_locked' => false, 'abc_override_reason' => null]
                : ['abc_class' => $class, 'abc_class_locked' => true, 'abc_override_reason' => $reason, 'abc_classified_at' => Carbon::now()]);

            return $audit;
        });
    }

    /**
     * @return list<array{stock_item_id: string, sku: string, name: string, consumption_value: string, cumulative_share: string, abc_class: string, rank: int, previous_class: ?string, is_manual_override: bool, is_new_item: bool}>
     */
    private function rank(int $periodDays, string $aShare, string $bShare, ?string $warehouseId): array
    {
        $since = Carbon::now()->subDays($periodDays);
        $policy = InventoryCountPolicy::forTenant($this->tenantId());

        $consumption = StockMovement::query()
            ->whereNotNull('stock_item_id')
            ->whereNotNull('source_location_id')
            ->whereNull('destination_location_id')
            ->whereNotIn('movement_type', self::NON_CONSUMPTION_TYPES)
            ->where('executed_at', '>=', $since)
            ->when($warehouseId !== null, static fn ($query) => $query->whereIn(
                'source_location_id',
                WarehouseBin::query()->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))->select('id')
            ))
            ->groupBy('stock_item_id')
            ->selectRaw('stock_item_id, SUM(total_valuation) AS consumption_value')
            ->toBase()
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->stock_item_id => Quantity::of((string) $row->consumption_value, 2)]);

        $items = StockItem::query()
            ->where('is_active', true)
            ->when($warehouseId !== null, static fn ($query) => $query->whereIn(
                'id',
                StockQuant::query()->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))->select('stock_item_id')
            ))
            ->get(['id', 'sku', 'name', 'abc_class', 'abc_class_locked', 'created_at'])
            ->keyBy('id');

        $entries = $items->keys()
            ->map(static fn ($id): array => ['id' => (string) $id, 'value' => $consumption->get((string) $id, '0.00')])
            ->values()
            ->all();

        $rows = [];

        foreach (AbcParetoClassifier::classify($entries, $aShare, $bShare) as $ranked) {
            /** @var StockItem $item */
            $item = $items->get($ranked['id']);
            $isOverride = (bool) $item->abc_class_locked && $item->abc_class !== null;
            $isNew = ! $isOverride && ! Quantity::isPositive($ranked['value']) && $item->created_at !== null && $item->created_at->greaterThanOrEqualTo($since);

            $rows[] = [
                'stock_item_id' => $ranked['id'],
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'consumption_value' => $ranked['value'],
                'cumulative_share' => $ranked['cumulative_share'],
                'abc_class' => match (true) {
                    $isOverride => (string) $item->abc_class,
                    $isNew => $policy->new_item_class,
                    default => $ranked['abc_class'],
                },
                'rank' => $ranked['rank'],
                'previous_class' => $item->abc_class,
                'is_manual_override' => $isOverride,
                'is_new_item' => $isNew,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{stock_item_id: string, abc_class: string, is_manual_override: bool}>  $rows
     */
    private function apply(array $rows): void
    {
        DB::transaction(static function () use ($rows): void {
            foreach (collect($rows)->reject(static fn (array $row): bool => $row['is_manual_override'])->groupBy('abc_class') as $class => $group) {
                StockItem::query()->whereIn('id', $group->pluck('stock_item_id'))->update(['abc_class' => $class, 'abc_classified_at' => Carbon::now()]);
            }
        });
    }

    private function tenantId(): ?string
    {
        $tenantId = $this->tenants->getTenantId();

        return $tenantId === null ? null : (string) $tenantId;
    }
}
