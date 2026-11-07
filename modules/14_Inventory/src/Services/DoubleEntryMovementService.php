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

final class DoubleEntryMovementService implements DoubleEntryMovementServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger
    ) {}

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

                if (! $sourceQuant || $sourceQuant->quantity_available < $dto->quantity) {
                    $available = $sourceQuant ? $sourceQuant->quantity_available : 0.0;
                    throw new StockRuleViolation("Insufficient available stock in bin [{$sourceBin->barcode}]. Required: {$dto->quantity}, Available: {$available}.");
                }

                $sourceQuant->decrement('quantity_on_hand', $dto->quantity);
            }

            // 2. Pessimistic Lock and Increment on Destination Quant (if not customer/scrap)
            if ($destBin !== null) {
                $this->lockedDestinationQuant($item, $destBin, $dto->lotId)->increment('quantity_on_hand', $dto->quantity);
            }

            // 3. Compute Unit Valuation
            $unitCost = $dto->customUnitCost ?? (float) $item->current_moving_average_cost;
            if ($unitCost <= 0.0) {
                $unitCost = (float) $item->standard_cost;
            }
            $totalValuation = round($unitCost * $dto->quantity, 2);

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
                'quantity' => $dto->quantity,
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
                'quantity_change' => $dto->quantity,
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
                quantity: $dto->quantity,
                movementType: $dto->movementType,
                totalValuation: $totalValuation,
                tenantId: $item->tenant_id,
                companyId: (string) ($item->company_id ?? '')
            );

            $this->dispatcher->publish($event);

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
            'quantity_on_hand' => 0.0000,
            'quantity_reserved' => 0.0000,
        ]);

        /** @var StockQuant $locked */
        $locked = StockQuant::withTrashed()->whereKey($quant->id)->lockForUpdate()->firstOrFail();

        if ($locked->trashed()) {
            $locked->forceFill(['quantity_on_hand' => 0, 'quantity_reserved' => 0, 'deleted_at' => null])->save();
        }

        return $locked;
    }
}
