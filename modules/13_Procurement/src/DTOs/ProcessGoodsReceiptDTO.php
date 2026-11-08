<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

/**
 * Goods received against a purchase order; with `inspectionRequired` the goods go to quarantine until inspected.
 */
final readonly class ProcessGoodsReceiptDTO
{
    /**
     * @param  array<int, array{purchase_order_line_id: string, quantity_received: float|string, quantity_accepted?: float|string|null, quantity_rejected?: float|string|null, lot_number?: ?string, serial_number?: ?string, warehouse_location_id?: ?string, warehouse_bin_id?: ?string, serial_numbers?: list<string>, rejection_reason?: ?string}>  $lines
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
        public ?string $companyId = null,
        public bool $inspectionRequired = false
    ) {}
}
