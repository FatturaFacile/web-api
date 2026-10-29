<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Events\StockReleasedEvent;
use Modules\Inventory\Events\StockReservedEvent;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;

final class StockReservationService implements StockReservationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
    ) {}

    public function allocate(StockReservationDTO $dto): StockReservation
    {
        return DB::transaction(function () use ($dto): StockReservation {
            /** @var StockItem $item */
            $item = StockItem::findOrFail($dto->stockItemId);
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::findOrFail($dto->warehouseId);

            // Find all quants in this warehouse for the item with available stock
            $quants = StockQuant::query()
                ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $warehouse->id))
                ->where('stock_item_id', $item->id)
                ->lockForUpdate()
                ->get();

            $totalAvailable = $quants->sum(fn (StockQuant $q) => $q->quantity_available);

            if ($totalAvailable < $dto->quantity) {
                throw new InvalidArgumentException("Insufficient available stock in warehouse [{$warehouse->name}]. Requested: {$dto->quantity}, Available: {$totalAvailable}.");
            }

            // Distribute reservation across quants
            $remainingToReserve = $dto->quantity;
            foreach ($quants as $quant) {
                if ($remainingToReserve <= 0.0) {
                    break;
                }

                $canReserve = min($quant->quantity_available, $remainingToReserve);
                if ($canReserve > 0.0) {
                    $quant->increment('quantity_reserved', $canReserve);
                    $remainingToReserve -= $canReserve;
                }
            }

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

            $event = new StockReservedEvent(
                reservationId: $reservation->id,
                stockItemId: $item->id,
                warehouseId: $warehouse->id,
                quantity: $dto->quantity,
                tenantId: $item->tenant_id,
                companyId: (string) ($item->company_id ?? '')
            );
            $this->dispatcher->publish($event);

            return $reservation;
        });
    }

    public function release(StockReservation $reservation): void
    {
        if ($reservation->status !== 'active') {
            return;
        }

        DB::transaction(function () use ($reservation): void {
            $quants = StockQuant::query()
                ->whereHas('bin.rack.aisle.zone', fn ($q) => $q->where('warehouse_id', $reservation->warehouse_id))
                ->where('stock_item_id', $reservation->stock_item_id)
                ->where('quantity_reserved', '>', 0)
                ->lockForUpdate()
                ->get();

            $remainingToRelease = (float) $reservation->quantity_reserved;
            foreach ($quants as $quant) {
                if ($remainingToRelease <= 0.0) {
                    break;
                }

                $canRelease = min((float) $quant->quantity_reserved, $remainingToRelease);
                if ($canRelease > 0.0) {
                    $quant->decrement('quantity_reserved', $canRelease);
                    $remainingToRelease -= $canRelease;
                }
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
            $this->dispatcher->publish($event);
        });
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
