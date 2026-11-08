<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\InventoryAuditEntry;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

/**
 * The one writer of stock: every quantity change of a quant goes through a double-entry movement from a source bin to
 * a destination bin (either side may be virtual: a supplier, a customer, production, scrap). Quants change under row
 * locks and never go below zero; the movement is recorded with its valuation, an audit entry and a domain event. A
 * movement touching a bin frozen by a physical count is refused (InventoryFreezeGuard).
 *
 * Quantities and values are computed as decimal strings (bcmath): the float of the DTO is converted once, at entry.
 */
final class DoubleEntryMovementService implements DoubleEntryMovementServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly InventoryFreezeGuard $freezeGuard
    ) {}

    /**
     * Executes one movement and returns it. Throws StockRuleViolation when the source quant has not enough available
     * stock (on hand minus reserved) or when a bin is frozen by a physical count.
     */
    public function executeTransfer(StockTransferDTO $dto): StockMovement
    {
        return DB::transaction(function () use ($dto): StockMovement {
            /** @var StockItem $item */
            $item = StockItem::findOrFail($dto->stockItemId);

            $sourceBin = $dto->sourceBinId !== null ? WarehouseBin::findOrFail($dto->sourceBinId) : null;
            $destBin = $dto->destinationBinId !== null ? WarehouseBin::findOrFail($dto->destinationBinId) : null;

            if ($sourceBin === null && $destBin === null) {
                throw new InvalidArgumentException('A movement must have at least a source or destination location.');
            }

            if ($sourceBin !== null && $destBin !== null && $sourceBin->id === $destBin->id) {
                throw new InvalidArgumentException('Source and destination bins cannot be identical.');
            }

            $quantity = Quantity::of($dto->quantity);

            if (! Quantity::isPositive($quantity)) {
                throw new InvalidArgumentException('A movement must move a positive quantity.');
            }

            $this->freezeGuard->assertMovable(array_values(array_filter([$sourceBin, $destBin])), $dto->originDocumentType, $dto->originDocumentId);

            // 1. Pessimistic Lock and Decrement on Source Quant (if not virtual supplier/production)
            if ($sourceBin !== null) {
                /** @var StockQuant|null $sourceQuant */
                $sourceQuant = StockQuant::query()
                    ->where('warehouse_bin_id', $sourceBin->id)
                    ->where('stock_item_id', $item->id)
                    ->when($dto->lotId !== null, fn ($q) => $q->where('lot_id', $dto->lotId))
                    ->orderByRaw('lot_id IS NULL DESC')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $available = $sourceQuant !== null ? Quantity::of(StockAllocationPlanner::available($sourceQuant)) : Quantity::of(0);

                if ($sourceQuant === null || Quantity::compare($available, $quantity) < 0) {
                    throw new StockRuleViolation("Insufficient available stock in bin [{$sourceBin->barcode}]. Required: {$quantity}, Available: {$available}.");
                }

                $sourceQuant->quantity_on_hand = Quantity::sub((string) $sourceQuant->quantity_on_hand, $quantity);
                $sourceQuant->save();
            }

            // 2. Pessimistic Lock and Increment on Destination Quant (if not customer/scrap)
            if ($destBin !== null) {
                $destinationQuant = $this->lockedDestinationQuant($item, $destBin, $dto->lotId);
                $destinationQuant->quantity_on_hand = Quantity::add((string) $destinationQuant->quantity_on_hand, $quantity);
                $destinationQuant->save();
            }

            // 3. Unit valuation: the cost given by the caller, else the moving average, else the standard cost.
            $unitCost = $dto->customUnitCost !== null ? Quantity::of($dto->customUnitCost) : Quantity::of((string) $item->current_moving_average_cost);
            if (! Quantity::isPositive($unitCost)) {
                $unitCost = Quantity::of((string) $item->standard_cost);
            }
            $totalValuation = Quantity::value($quantity, $unitCost);

            // 4. Create Immutable Double-Entry Stock Movement
            $movement = StockMovement::create([
                'tenant_id' => $item->tenant_id,
                'company_id' => $item->company_id,
                'warehouse_id' => $sourceBin?->rack?->aisle?->zone?->warehouse_id ?? $destBin?->rack?->aisle?->zone?->warehouse_id,
                'product_id' => null,
                'type' => 'transfer',
                'stock_item_id' => $item->id,
                'source_location_id' => $sourceBin?->id,
                'destination_location_id' => $destBin?->id,
                'lot_id' => $dto->lotId,
                'serial_number_id' => $dto->serialNumberId,
                'quantity' => $quantity,
                'uom' => $item->uom,
                'unit_cost' => $unitCost,
                'total_valuation' => $totalValuation,
                'movement_type' => $dto->movementType,
                'origin_document_type' => $dto->originDocumentType,
                'origin_document_id' => $dto->originDocumentId,
                'executed_by_user_id' => $dto->userId ?? auth()->id(),
                'executed_at' => $dto->executedAt !== null ? Carbon::parse($dto->executedAt) : Carbon::now(),
                'notes' => $dto->notes,
            ]);

            // 5. Forensic Audit Entry: the real actor, or none for a movement run by the system.
            $actorUserId = $dto->userId ?? auth()->id();

            InventoryAuditEntry::create([
                'tenant_id' => $item->tenant_id,
                'company_id' => $item->company_id,
                'stock_item_id' => $item->id,
                'movement_id' => $movement->id,
                'actor_user_id' => $actorUserId,
                'event_type' => 'stock_transferred',
                'quantity_change' => $quantity,
                'valuation_impact' => $totalValuation,
                'source_location_desc' => $sourceBin?->barcode ?? 'VIRTUAL_SOURCE',
                'dest_location_desc' => $destBin?->barcode ?? 'VIRTUAL_DEST',
                'reason_code' => $dto->movementType,
                'ip_address' => request()?->ip(),
            ]);

            $this->auditLedger->record(
                action: 'created',
                model: $movement,
                payloadBefore: null,
                payloadAfter: $movement->toArray(),
                moduleCode: 'MODULE_14_INVENTORY'
            );

            // 6. Dispatch Domain Event
            $event = new StockTransferredEvent(
                movementId: $movement->id,
                stockItemId: $item->id,
                sourceBinId: $sourceBin?->id,
                destinationBinId: $destBin?->id,
                quantity: (float) $quantity,
                movementType: $dto->movementType,
                totalValuation: (float) $totalValuation,
                tenantId: $item->tenant_id,
                companyId: (string) ($item->company_id ?? '')
            );

            $this->dispatcher->publishAndDispatch($event);

            return $movement;
        });
    }

    /**
     * The quant of the destination slot, created when missing and locked FOR UPDATE. The slot (bin, item, lot) is unique
     * also without a lot (`NULLS NOT DISTINCT`), so two concurrent movements cannot create it twice: the loser of the
     * insert race reads the winner's row (`createOrFirst`). A quant deleted earlier is brought back empty.
     */
    private function lockedDestinationQuant(StockItem $item, WarehouseBin $bin, ?string $lotId): StockQuant
    {
        $slot = ['warehouse_bin_id' => $bin->id, 'stock_item_id' => $item->id, 'lot_id' => $lotId];

        /** @var StockQuant $quant */
        $quant = StockQuant::withTrashed()->firstOrCreate($slot, [
            'tenant_id' => $item->tenant_id,
            'company_id' => $item->company_id,
            'quantity_on_hand' => '0.0000',
            'quantity_reserved' => '0.0000',
        ]);

        /** @var StockQuant $locked */
        $locked = StockQuant::withTrashed()->whereKey($quant->id)->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            $locked->forceFill(['quantity_on_hand' => 0, 'quantity_reserved' => 0, 'deleted_at' => null])->save();
        }

        return $locked;
    }
}
