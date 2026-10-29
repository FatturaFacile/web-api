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

final readonly class ProcurementGoodsReceivedListener implements ShouldQueue
{
    public function __construct(
        private DoubleEntryMovementServiceInterface $movementService
    ) {}

    public function handle(GoodsReceivedEvent $event): void
    {
        // Find default or receiving warehouse for company
        $warehouse = Warehouse::where('company_id', $event->companyId)
            ->where('is_active', true)
            ->first() ?? Warehouse::first();

        if (! $warehouse) {
            return;
        }

        // Receiving / Inbound bin
        $receivingBin = WarehouseBin::where('warehouse_id', $warehouse->id)
            ->where('bin_type', 'inbound')
            ->first() ?? WarehouseBin::where('warehouse_id', $warehouse->id)->first();

        if (! $receivingBin) {
            return;
        }

        foreach ($event->receivedLines as $line) {
            $acceptedQty = (float) ($line['quantity_accepted'] ?? $line['quantity_received'] ?? 0);
            if ($acceptedQty <= 0) {
                continue;
            }

            // Look up purchase order line to get product/sku
            $poLine = DB::table('purchase_order_lines')
                ->where('id', $line['purchase_order_line_id'])
                ->first();

            $item = null;
            if ($poLine && isset($poLine->product_id)) {
                $item = StockItem::find($poLine->product_id);
            }
            if (! $item) {
                $item = StockItem::first();
            }

            if (! $item) {
                continue;
            }

            $lotId = null;
            if (! empty($line['lot_number'])) {
                $lot = StockLot::firstOrCreate(
                    [
                        'stock_item_id' => $item->id,
                        'lot_number' => $line['lot_number'],
                    ],
                    [
                        'tenant_id' => $event->tenantId,
                        'company_id' => $event->companyId,
                        'received_at' => now(),
                        'initial_quantity' => $acceptedQty,
                        'current_quantity' => $acceptedQty,
                        'status' => 'active',
                    ]
                );
                $lotId = (string) $lot->id;
            }

            $unitCost = (float) ($poLine->unit_price ?? $item->standard_cost ?? 0.0);

            $this->movementService->executeTransfer(new StockTransferDTO(
                stockItemId: (string) $item->id,
                warehouseId: (string) $warehouse->id,
                quantity: $acceptedQty,
                movementType: 'PURCHASE_RECEIPT',
                sourceBinId: null, // external inbound
                destinationBinId: (string) $receivingBin->id,
                stockLotId: $lotId,
                unitCost: $unitCost,
                referenceType: 'purchase_receipts',
                referenceId: $event->receiptId,
                userId: 'system',
                companyId: $event->companyId
            ));
        }
    }
}
