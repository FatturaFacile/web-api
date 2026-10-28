<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class ProcessGoodsReceiptDTO
{
    /**
     * @param  array<int, array{purchase_order_line_id: string, quantity_received: float, quantity_accepted: float, quantity_rejected?: float, lot_number?: ?string, serial_number?: ?string, warehouse_location_id?: ?string, rejection_reason?: ?string}>  $lines
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $supplierDeliveryNoteRef,
        public string $receivedDate,
        public string $receivedByUserId,
        public array $lines,
        public ?string $receiptNumber = null,
        public ?string $warehouseId = null,
        public string $inspectionStatus = 'passed',
        public ?string $notes = null,
        public ?string $tenantId = null,
        public ?string $companyId = null
    ) {}
}
