<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Events\StockReleasedEvent;
use Modules\Inventory\Events\StockReservedEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\StockReservationAllocation;
use Modules\Inventory\Models\Warehouse;

final class StockReservationService implements StockReservationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly StockAllocationPlanner $planner
    ) {}

    public function allocate(StockReservationDTO $dto): StockReservation
    {
        return DB::transaction(function () use ($dto): StockReservation {
            /** @var StockItem $item */
            $item = StockItem::findOrFail($dto->stockItemId);
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::findOrFail($dto->warehouseId);

            $quants = $this->planner->lockEligibleQuants($item, $warehouse, $dto->lotId, $dto->warehouseBinId);
            $shares = $this->planner->split($quants, bcadd((string) $dto->quantity, '0', 4), (string) $warehouse->name);

            $reservation = StockReservation::create([
                'tenant_id' => $item->tenant_id,
                'company_id' => $item->company_id,
                'stock_item_id' => $item->id,
                'warehouse_id' => $warehouse->id,
                'reserved_for_type' => $dto->reservedForType,
                'reserved_for_id' => $dto->reservedForId,
                'quantity_reserved' => $dto->quantity,
                'status' => 'active',
                'expires_at' => $dto->expiresAt ? Carbon::parse($dto->expiresAt) : Carbon::now()->addDays(7),
                'created_by' => $dto->userId ?? auth()->id(),
            ]);

            foreach ($shares as $share) {
                $quant = $share['quant'];
                $quant->quantity_reserved = bcadd((string) $quant->quantity_reserved, $share['quantity'], 4);
                $quant->save();

                StockReservationAllocation::create([
                    'tenant_id' => $reservation->tenant_id,
                    'stock_reservation_id' => $reservation->id,
                    'stock_quant_id' => $quant->id,
                    'lot_id' => $quant->lot_id,
                    'warehouse_bin_id' => $quant->warehouse_bin_id,
                    'quantity' => bcadd($share['quantity'], '0', 4),
                ]);
            }

            $event = new StockReservedEvent(
                reservationId: $reservation->id,
                stockItemId: $item->id,
                warehouseId: $warehouse->id,
                quantity: $dto->quantity,
                tenantId: $item->tenant_id,
                companyId: (string) ($item->company_id ?? '')
            );
            $this->dispatcher->publishAndDispatch($event);

            return $reservation;
        });
    }

    public function release(StockReservation $reservation): void
    {
        if ($reservation->status !== 'active') {
            return;
        }

        DB::transaction(function () use ($reservation): void {
            $allocations = StockReservationAllocation::query()->where('stock_reservation_id', $reservation->id)->get();

            if ($allocations->isEmpty()) {
                $this->releaseWithoutAllocations($reservation);
            }

            $quants = StockQuant::query()->whereIn('id', $allocations->pluck('stock_quant_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $quant = $quants->get($allocation->stock_quant_id);

                if ($quant !== null) {
                    $reserved = bcsub((string) $quant->quantity_reserved, (string) $allocation->quantity, 4);
                    $quant->quantity_reserved = bccomp($reserved, '0', 4) < 0 ? '0.0000' : $reserved;
                    $quant->save();
                }

                $allocation->delete();
            }

            $reservation->update(['status' => 'released']);

            $event = new StockReleasedEvent(
                reservationId: $reservation->id,
                stockItemId: $reservation->stock_item_id,
                warehouseId: $reservation->warehouse_id,
                quantity: (float) $reservation->quantity_reserved,
                tenantId: $reservation->tenant_id,
                companyId: (string) ($reservation->company_id ?? '')
            );
            $this->dispatcher->publishAndDispatch($event);
        });
    }

    /**
     * Reservations made before allocations were recorded say nothing about their quants: the units go back to any
     * quant of the item in the warehouse, as they always did.
     */
    private function releaseWithoutAllocations(StockReservation $reservation): void
    {
        $quants = StockQuant::query()
            ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $reservation->warehouse_id))
            ->where('stock_item_id', $reservation->stock_item_id)
            ->where('quantity_reserved', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = (string) $reservation->quantity_reserved;

        foreach ($quants as $quant) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }

            $share = bccomp((string) $quant->quantity_reserved, $remaining, 4) < 0 ? (string) $quant->quantity_reserved : $remaining;
            $quant->quantity_reserved = bcsub((string) $quant->quantity_reserved, $share, 4);
            $quant->save();
            $remaining = bcsub($remaining, $share, 4);
        }
    }

    public function expireStaleReservations(): int
    {
        $staleReservations = StockReservation::query()
            ->where('status', 'active')
            ->where('expires_at', '<', Carbon::now())
            ->get();

        $count = 0;
        foreach ($staleReservations as $res) {
            $this->release($res);
            $res->update(['status' => 'cancelled']);
            $count++;
        }

        return $count;
    }
}
