<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockPickLine;
use Modules\Inventory\Models\StockPickList;
use Modules\Inventory\Models\StockReservationAllocation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\PickRouteOptimizer;
use Modules\Inventory\Services\StockAllocationPlanner;

/**
 * Builds a pick list: each request takes its quants in FEFO or FIFO order and holds them, or follows the allocations of
 * an existing reservation, then the stops are put in walking order. What the warehouse cannot cover is listed in
 * `shortages`, not invented as a line.
 */
final readonly class GeneratePickListAction
{
    public function __construct(
        private StockAllocationPlanner $planner,
        private PickRouteOptimizer $route,
        private DocumentNumberingService $numbering
    ) {}

    /**
     * @param  array<int, array{stock_item_id: string, quantity?: float|string|null, lot_id?: ?string, reservation_id?: ?string}>  $items
     */
    public function execute(
        string $warehouseId,
        array $items,
        ?string $assignedPickerId = null,
        ?string $originType = null,
        ?string $originId = null,
        ?string $waveId = null
    ): StockPickList {
        return DB::transaction(function () use ($warehouseId, $items, $assignedPickerId, $originType, $originId, $waveId): StockPickList {
            $warehouse = Warehouse::findOrFail($warehouseId);
            $stops = [];
            $shortages = [];

            foreach ($items as $request) {
                $item = StockItem::findOrFail($request['stock_item_id']);

                if (! empty($request['reservation_id'])) {
                    array_push($stops, ...$this->stopsFromReservation($item, (string) $request['reservation_id']));

                    continue;
                }

                $requested = bcadd((string) ($request['quantity'] ?? '0'), '0', 4);
                $quants = $this->planner->lockEligibleQuants($item, $warehouse, $request['lot_id'] ?? null);
                ['shares' => $shares, 'short' => $short] = $this->planner->take($quants, $requested);

                foreach ($shares as $share) {
                    $quant = $share['quant'];
                    $quant->quantity_reserved = bcadd((string) $quant->quantity_reserved, $share['quantity'], 4);
                    $quant->save();

                    $stops[] = $this->stop($item, $quant->warehouse_bin_id, $quant->lot_id, $share['quantity']);
                }

                if (Decimal::isPositive($short)) {
                    $shortages[] = ['stock_item_id' => $item->id, 'requested' => $requested, 'short' => bcadd($short, '0', 4)];
                }
            }

            if ($stops === []) {
                throw new StockRuleViolation("Nothing can be picked from warehouse [{$warehouse->name}].");
            }

            $pickList = StockPickList::create([
                'tenant_id' => $warehouse->tenant_id,
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'pick_list_number' => $this->numbering->next('pick_list', null, (string) $warehouse->tenant_id)['number'],
                'assigned_picker_user_id' => $assignedPickerId,
                'status' => $assignedPickerId === null ? 'draft' : 'assigned',
                'origin_document_type' => $originType,
                'origin_document_id' => $originId,
                'shortages' => $shortages === [] ? null : $shortages,
                'pick_wave_id' => $waveId,
            ]);

            $bins = WarehouseBin::query()->with('rack.aisle.zone')->whereIn('id', array_column($stops, 'warehouse_bin_id'))->get()->keyBy('id');

            foreach ($this->route->sequence($stops, $bins) as $sequence => $stop) {
                StockPickLine::create($stop + [
                    'tenant_id' => $warehouse->tenant_id,
                    'stock_pick_list_id' => $pickList->id,
                    'quantity_picked' => 0,
                    'status' => 'pending',
                    'sequence_number' => $sequence + 1,
                ]);
            }

            return $pickList->load('lines');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stopsFromReservation(StockItem $item, string $reservationId): array
    {
        $allocations = StockReservationAllocation::query()
            ->where('stock_reservation_id', $reservationId)
            ->whereHas('reservation', static fn ($reservation) => $reservation->where('status', 'active')->where('stock_item_id', $item->id))
            ->get();

        if ($allocations->isEmpty()) {
            throw new StockRuleViolation("Reservation [{$reservationId}] has nothing active to pick for item [{$item->sku}].");
        }

        return $allocations
            ->map(fn (StockReservationAllocation $allocation): array => $this->stop($item, $allocation->warehouse_bin_id, $allocation->lot_id, (string) $allocation->quantity, $reservationId))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stop(StockItem $item, string $binId, ?string $lotId, string $quantity, ?string $reservationId = null): array
    {
        return [
            'stock_item_id' => $item->id,
            'warehouse_bin_id' => $binId,
            'lot_id' => $lotId,
            'quantity_to_pick' => bcadd($quantity, '0', 4),
            'stock_reservation_id' => $reservationId,
        ];
    }
}
