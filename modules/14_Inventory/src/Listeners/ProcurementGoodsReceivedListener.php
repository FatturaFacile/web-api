<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Procurement\Events\GoodsReceivedEvent;

/**
 * Books goods receipts into stock. Each receipt movement carries `origin_document_type =
 * purchase_receipt` / `origin_document_id = receipt id` and the PO line unit price, so that the
 * valuation engine creates a cost layer linked to the receipt (landed cost allocation target).
 *
 * Purchase order lines carry no stock item reference: the item is resolved from the received line
 * (`stock_item_id`/`product_id`, when supplied) or the PO line `product_id`; unresolved lines are skipped.
 */
final readonly class ProcurementGoodsReceivedListener implements ShouldQueue
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movementService
    ) {}

    public function handle(GoodsReceivedEvent $event): void
    {
        $warehouse = Warehouse::query()->where('company_id', $event->companyId)->where('is_active', true)->first()
            ?? Warehouse::query()->where('is_active', true)->first()
            ?? Warehouse::query()->first();

        if ($warehouse === null) {
            return;
        }

        $receivingBin = WarehouseBin::query()
            ->whereHas('rack.aisle.zone', fn ($query) => $query->where('warehouse_id', $warehouse->id))
            ->where('is_locked', false)
            ->orderBy('code')
            ->first();

        if ($receivingBin === null) {
            return;
        }

        foreach ($event->receivedLines as $line) {
            $acceptedQty = (float) ($line['quantity_accepted'] ?? $line['quantity_received'] ?? 0);
            if ($acceptedQty <= 0) {
                continue;
            }

            $poLine = isset($line['purchase_order_line_id'])
                ? DB::table('purchase_order_lines')->where('id', $line['purchase_order_line_id'])->first()
                : null;

            $itemId = $line['stock_item_id'] ?? $line['product_id'] ?? ($poLine->product_id ?? null);
            $item = $itemId !== null ? StockItem::query()->find($itemId) : null;
            if ($item === null) {
                continue;
            }

            $lotId = null;
            if (! empty($line['lot_number'])) {
                $lot = StockLot::query()->firstOrCreate(
                    [
                        'stock_item_id' => $item->id,
                        'lot_number' => $line['lot_number'],
                    ],
                    [
                        'tenant_id' => $event->tenantId,
                        'company_id' => $event->companyId,
                    ]
                );
                $lotId = (string) $lot->id;
            }

            $unitCost = $poLine !== null && isset($poLine->unit_price)
                ? (float) $poLine->unit_price
                : (float) $item->standard_cost;

            $this->movementService->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $item->id,
                sourceBinId: null,
                destinationBinId: (string) $receivingBin->id,
                quantity: $acceptedQty,
                movementType: 'PURCHASE_RECEIPT',
                lotId: $lotId,
                originDocumentType: 'purchase_receipt',
                originDocumentId: $event->receiptId,
                customUnitCost: $unitCost,
                notes: "Goods receipt {$event->receiptNumber}",
            ));
        }
    }
}
