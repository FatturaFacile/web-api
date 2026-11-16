<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\DTOs\InboundCostingDTO;
use Modules\Inventory\DTOs\OutboundCostingDTO;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Events\StockTransferredEvent;
use Modules\Inventory\Models\InventoryAuditEntry;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Decimal;

final class DoubleEntryMovementService implements DoubleEntryMovementServiceInterface
{
    /**
     * Movement types (lower-cased) treated as purchase receipts for purchase price variance.
     */
    private const array RECEIPT_MOVEMENT_TYPES = ['purchase_receipt', 'supplier_receipt', 'receipt', 'goods_receipt'];

    /**
     * Origin document types identifying a Procurement goods receipt (landed cost target).
     */
    private const array RECEIPT_ORIGIN_TYPES = ['purchase_receipt', 'purchase_receipts'];

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly InventoryCostingServiceInterface $costing
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

            // Item-wide on-hand before this movement (valuation hooks need it).
            $onHandBefore = Decimal::quantity((string) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand'));

            // 1. Pessimistic Lock and Decrement on Source Quant (if not virtual supplier/production)
            if ($sourceBin !== null) {
                /** @var StockQuant|null $sourceQuant */
                $sourceQuant = StockQuant::query()
                    ->where('warehouse_bin_id', $sourceBin->id)
                    ->where('stock_item_id', $item->id)
                    ->when($dto->lotId !== null, fn ($q) => $q->where('lot_id', $dto->lotId))
                    ->lockForUpdate()
                    ->first();

                if (! $sourceQuant || $sourceQuant->quantity_available < $dto->quantity) {
                    $available = $sourceQuant ? $sourceQuant->quantity_available : 0.0;
                    throw new InvalidArgumentException("Insufficient available stock in bin [{$sourceBin->barcode}]. Required: {$dto->quantity}, Available: {$available}.");
                }

                $sourceQuant->decrement('quantity_on_hand', $dto->quantity);
            }

            // 2. Pessimistic Lock and Increment on Destination Quant (if not customer/scrap)
            if ($destBin !== null) {
                /** @var StockQuant $destQuant */
                $destQuant = StockQuant::query()->firstOrCreate(
                    [
                        'tenant_id' => $item->tenant_id,
                        'warehouse_bin_id' => $destBin->id,
                        'stock_item_id' => $item->id,
                        'lot_id' => $dto->lotId,
                    ],
                    [
                        'company_id' => $item->company_id,
                        'quantity_on_hand' => 0.0000,
                        'quantity_reserved' => 0.0000,
                    ]
                );

                $destQuant = StockQuant::query()->where('id', $destQuant->id)->lockForUpdate()->first();
                $destQuant->increment('quantity_on_hand', $dto->quantity);
            }

            // 3. Compute Unit Valuation
            $unitCost = $dto->customUnitCost ?? (float) $item->current_moving_average_cost;
            if ($unitCost <= 0.0) {
                $unitCost = (float) $item->standard_cost;
            }
            $totalValuation = round($unitCost * $dto->quantity, 2);

            $movementId = (string) Str::uuid7();
            $warehouseId = $sourceBin?->rack?->aisle?->zone?->warehouse_id ?? $destBin?->rack?->aisle?->zone?->warehouse_id;
            $isReceiptOrigin = $dto->originDocumentType !== null
                && in_array(strtolower($dto->originDocumentType), self::RECEIPT_ORIGIN_TYPES, true);

            // 3b. Valuation hooks (FatturaFacile/FatturaFacile#144): inbound creates a cost layer
            // (and PPV for standard cost), outbound depletes layers per the effective method.
            if ($sourceBin === null && $destBin !== null) {
                $isReceipt = $isReceiptOrigin || in_array(strtolower($dto->movementType), self::RECEIPT_MOVEMENT_TYPES, true);
                $this->costing->recordInbound($item, new InboundCostingDTO(
                    movementId: $movementId,
                    quantity: Decimal::of($dto->quantity),
                    unitCost: Decimal::of($unitCost),
                    onHandBefore: $onHandBefore,
                    sourceType: $isReceipt ? 'receipt' : 'adjustment',
                    warehouseId: $warehouseId !== null ? (string) $warehouseId : null,
                    lotId: $dto->lotId,
                    receiptId: $isReceiptOrigin ? $dto->originDocumentId : null,
                ));
            } elseif ($sourceBin !== null && $destBin === null) {
                $outbound = $this->costing->consumeOutbound($item, new OutboundCostingDTO(
                    movementId: $movementId,
                    quantity: Decimal::of($dto->quantity),
                    onHandBefore: $onHandBefore,
                    lotId: $dto->lotId,
                    warehouseId: $warehouseId !== null ? (string) $warehouseId : null,
                ));
                $unitCost = (float) $outbound->unitCost;
                $totalValuation = (float) $outbound->cogsAmount;
            }

            // 4. Create Immutable Double-Entry Stock Movement
            $movement = StockMovement::create([
                'id' => $movementId,
                'tenant_id' => $item->tenant_id,
                'company_id' => $item->company_id,
                'warehouse_id' => $warehouseId,
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
                'executed_at' => Carbon::now(),
                'notes' => $dto->notes,
            ]);

            // 5. Forensic Audit Entry
            $actorUserId = $dto->userId ?? auth()->id() ?? DB::table('tenant_user')->where('tenant_id', $item->tenant_id)->value('user_id') ?? (string) Str::uuid7();

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
}
