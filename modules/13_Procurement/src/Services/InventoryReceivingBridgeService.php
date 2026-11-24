<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Modules\Procurement\Contracts\InventoryReceivingBridgeInterface;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReturn;

final class InventoryReceivingBridgeService implements InventoryReceivingBridgeInterface
{
    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger
    ) {}

    public function recordStockReceipt(PurchaseReceipt $receipt): void
    {
        // Integration hook for Module 14: generates quant increment transaction
        $this->auditLedger->record(
            action: 'INVENTORY_RECEIVING_POSTED',
            model: $receipt,
            payloadBefore: null,
            payloadAfter: [
                'receipt_id' => $receipt->id,
                'po_id' => $receipt->purchase_order_id,
                'warehouse_id' => $receipt->warehouse_id,
                'lines_count' => $receipt->lines()->count(),
            ]
        );
    }

    public function recordStockReturn(PurchaseReturn $return): void
    {
        // Integration hook for Module 14: generates quant deduction transaction
        $this->auditLedger->record(
            action: 'INVENTORY_RETURN_POSTED',
            model: $return,
            payloadBefore: null,
            payloadAfter: [
                'return_id' => $return->id,
                'po_id' => $return->purchase_order_id,
                'lines_count' => $return->lines()->count(),
            ]
        );
    }
}
