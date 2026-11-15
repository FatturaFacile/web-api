<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Wms\Models\WmsSlottingRecommendation;
use Modules\Wms\Models\WmsSlottingRule;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Support\WarehouseBins;

/**
 * Slotting: where should an item live? The pick velocity (picks per day, from the stock movements that leave a bin of the
 * warehouse) ranks the items; the first 80 percent of the picks make class A, up to 95 percent class B, the rest class C.
 * The active slotting rules (or, without a matching rule, A picking, B storage, C bulk) say which bin type suits the item;
 * an item stored in another kind of bin gets a recommendation to move to a free, unlocked bin of the right type.
 */
final class SlottingService
{
    /** Movement types that take stock out of a bin without being a pick. */
    private const array NonPickTypes = ['adjustment', 'adjustment_out', 'ADJUSTMENT_OUT', 'scrap_loss', 'SCRAP', 'scrap'];

    private const array DefaultBinTypes = ['A' => 'picking', 'B' => 'storage', 'C' => 'bulk'];

    private const string ClassAShare = '80';

    private const string ClassBShare = '95';

    public function __construct(private readonly TaskService $tasks) {}

    /**
     * @return array{summary: array{analysed_items: int, recommendations: int, without_free_bin: int, period_days: int}, recommendations: Collection<int, WmsSlottingRecommendation>}
     */
    public function generate(string $tenantId, string $warehouseId, int $periodDays): array
    {
        return DB::transaction(function () use ($tenantId, $warehouseId, $periodDays): array {
            $bins = WarehouseBins::query($tenantId)->where('z.warehouse_id', $warehouseId)->get()->keyBy('id');
            $picks = $this->picksPerItem($tenantId, $warehouseId, $periodDays);
            $stock = $this->stockPerItem($tenantId, $warehouseId);
            $occupied = array_fill_keys(array_unique(array_merge(...array_map(static fn (array $rows): array => array_keys($rows), array_values($stock) ?: [[]]))), true);
            $rules = WmsSlottingRule::query()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('is_active', true)->orderBy('priority')->orderBy('created_at')->get();

            $classes = $this->classify($picks, array_keys($stock));
            $taken = [];
            $created = [];
            $withoutBin = 0;

            WmsSlottingRecommendation::query()->where('tenant_id', $tenantId)->where('warehouse_id', $warehouseId)->where('status', WmsSlottingRecommendation::Pending)->delete();

            foreach ($classes as $itemId => $class) {
                $itemBins = $stock[$itemId];
                $velocity = $this->velocity($picks[$itemId] ?? 0, $periodDays);
                $rule = $rules->first(static fn (WmsSlottingRule $candidate): bool => ($candidate->abc_class === null || $candidate->abc_class === $class)
                    && ($candidate->min_velocity === null || bccomp($velocity, (string) $candidate->min_velocity, 4) >= 0)
                    && ($candidate->max_velocity === null || bccomp($velocity, (string) $candidate->max_velocity, 4) < 0));
                $wantedType = $rule?->target_bin_type ?? self::DefaultBinTypes[$class];
                $wantedZone = $rule?->target_zone_id;

                // The bin that holds most of the item is the one to move it from.
                arsort($itemBins);
                $fromId = (string) array_key_first($itemBins);
                $from = $bins->get($fromId);

                if ($from === null || ($from->bin_type === $wantedType && ($wantedZone === null || $from->zone_id === $wantedZone))) {
                    continue;
                }

                $candidates = $bins->filter(static fn (object $bin): bool => $bin->bin_type === $wantedType
                    && ! (bool) $bin->is_locked
                    && ($wantedZone === null || $bin->zone_id === $wantedZone)
                    && ! isset($occupied[$bin->id])
                    && ! isset($taken[$bin->id]))
                    ->sortBy([
                        static fn (object $a, object $b): int => ($class === 'C' ? -1 : 1) * (($a->pick_sequence ?? PHP_INT_MAX) <=> ($b->pick_sequence ?? PHP_INT_MAX)),
                        static fn (object $a, object $b): int => strcmp((string) $a->code, (string) $b->code),
                    ]);
                $target = $candidates->first();

                if ($target === null) {
                    $withoutBin++;

                    continue;
                }

                $taken[$target->id] = true;
                $created[] = WmsSlottingRecommendation::create([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'stock_item_id' => $itemId,
                    'slotting_rule_id' => $rule?->id,
                    'abc_class' => $class,
                    'velocity' => $velocity,
                    'period_days' => $periodDays,
                    'from_bin_id' => $fromId,
                    'to_bin_id' => $target->id,
                    'target_bin_type' => $wantedType,
                    'reason' => __('wms_errors.slotting.reason', ['class' => $class, 'velocity' => $velocity, 'from' => $from->bin_type, 'to' => $wantedType]),
                ])->load(['stockItem:id,sku,name', 'fromBin:id,code', 'toBin:id,code']);
            }

            return [
                'summary' => ['analysed_items' => count($stock), 'recommendations' => count($created), 'without_free_bin' => $withoutBin, 'period_days' => $periodDays],
                'recommendations' => new Collection($created),
            ];
        });
    }

    /**
     * Creates the move task that carries the whole stock of the item from its bin to the recommended bin.
     */
    public function accept(WmsSlottingRecommendation $recommendation): WmsSlottingRecommendation
    {
        return DB::transaction(function () use ($recommendation): WmsSlottingRecommendation {
            $locked = WmsSlottingRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            $this->assertPending($locked);

            $quantity = $locked->from_bin_id === null ? '0' : (string) DB::table('stock_quants')
                ->where('tenant_id', $locked->tenant_id)
                ->where('warehouse_bin_id', $locked->from_bin_id)
                ->where('stock_item_id', $locked->stock_item_id)
                ->sum('quantity_on_hand');

            if (bccomp($quantity, '0', 4) <= 0) {
                throw ValidationException::withMessages(['status' => [__('wms_errors.slotting.stock_moved')]]);
            }

            /** @var WmsTask $task */
            $task = $this->tasks->create((string) $locked->tenant_id, [
                'warehouse_id' => $locked->warehouse_id,
                'task_type' => 'move',
                'priority' => 40,
                'stock_item_id' => $locked->stock_item_id,
                'source_bin_id' => $locked->from_bin_id,
                'destination_bin_id' => $locked->to_bin_id,
                'planned_quantity' => bcadd($quantity, '0', 4),
                'reference' => 'slotting:'.$locked->id,
            ]);

            $locked->update(['status' => WmsSlottingRecommendation::Accepted, 'task_id' => $task->id, 'decided_at' => now()]);

            return $this->present($locked);
        });
    }

    public function reject(WmsSlottingRecommendation $recommendation): WmsSlottingRecommendation
    {
        return DB::transaction(function () use ($recommendation): WmsSlottingRecommendation {
            $locked = WmsSlottingRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            $this->assertPending($locked);
            $locked->update(['status' => WmsSlottingRecommendation::Rejected, 'decided_at' => now()]);

            return $this->present($locked);
        });
    }

    private function present(WmsSlottingRecommendation $recommendation): WmsSlottingRecommendation
    {
        return $recommendation->refresh()->load(['stockItem:id,sku,name', 'fromBin:id,code', 'toBin:id,code']);
    }

    private function assertPending(WmsSlottingRecommendation $recommendation): void
    {
        if ($recommendation->status !== WmsSlottingRecommendation::Pending) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.slotting.not_pending', ['status' => $recommendation->status])]]);
        }
    }

    /**
     * Pick lines per item: the movements that leave a bin of the warehouse for good.
     *
     * @return array<string, int>
     */
    private function picksPerItem(string $tenantId, string $warehouseId, int $periodDays): array
    {
        return DB::table('stock_movements as m')
            ->join('warehouse_bins as b', 'b.id', '=', 'm.source_location_id')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->where('m.tenant_id', $tenantId)
            ->where('z.warehouse_id', $warehouseId)
            ->whereNotNull('m.stock_item_id')
            ->whereNull('m.destination_location_id')
            ->whereNotIn('m.movement_type', self::NonPickTypes)
            ->where('m.executed_at', '>=', now()->subDays($periodDays))
            ->groupBy('m.stock_item_id')
            ->selectRaw('m.stock_item_id as item_id, COUNT(*) as picks')
            ->get()
            ->mapWithKeys(static fn (object $row): array => [(string) $row->item_id => (int) $row->picks])
            ->all();
    }

    /**
     * Quantity on hand per item and bin of the warehouse.
     *
     * @return array<string, array<string, float>>
     */
    private function stockPerItem(string $tenantId, string $warehouseId): array
    {
        $rows = DB::table('stock_quants as q')
            ->join('warehouse_bins as b', 'b.id', '=', 'q.warehouse_bin_id')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->where('q.tenant_id', $tenantId)
            ->where('z.warehouse_id', $warehouseId)
            ->where('q.quantity_on_hand', '>', 0)
            ->groupBy('q.stock_item_id', 'q.warehouse_bin_id')
            ->selectRaw('q.stock_item_id as item_id, q.warehouse_bin_id as bin_id, SUM(q.quantity_on_hand) as quantity')
            ->get();

        $stock = [];

        foreach ($rows as $row) {
            $stock[(string) $row->item_id][(string) $row->bin_id] = (float) $row->quantity;
        }

        ksort($stock);

        return $stock;
    }

    /**
     * ABC class of every stocked item by the share of the picks that come before it: the items that make the first 80
     * percent of the picks are A, up to 95 percent B, the rest (and the items never picked) C.
     *
     * @param  array<string, int>  $picks
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    private function classify(array $picks, array $itemIds): array
    {
        $total = array_sum(array_intersect_key($picks, array_flip($itemIds)));
        $ranked = $itemIds;
        usort($ranked, static fn (string $left, string $right): int => (($picks[$right] ?? 0) <=> ($picks[$left] ?? 0)) ?: strcmp($left, $right));

        $classes = [];
        $before = 0;

        foreach ($ranked as $itemId) {
            $count = $picks[$itemId] ?? 0;
            $share = $total > 0 ? bcdiv(bcmul((string) $before, '100', 4), (string) $total, 4) : '100';
            $classes[$itemId] = match (true) {
                $count === 0 => 'C',
                bccomp($share, self::ClassAShare, 4) < 0 => 'A',
                bccomp($share, self::ClassBShare, 4) < 0 => 'B',
                default => 'C',
            };
            $before += $count;
        }

        return $classes;
    }

    private function velocity(int $picks, int $periodDays): string
    {
        return bcdiv((string) $picks, (string) $periodDays, 4);
    }
}
