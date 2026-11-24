<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

/**
 * A return to supplier: `credit` asks for a credit note, `replacement` reopens the quantity on the order.
 */
final readonly class ProcessPurchaseReturnDTO
{
    /**
     * @param  array<int, array{purchase_order_line_id?: string, purchase_receipt_line_id?: string, quantity_returned: float|string, reason?: ?string}>  $lines
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $supplierId,
        public string $returnDate,
        public string $returnedByUserId,
        public array $lines,
        public ?string $purchaseReceiptId = null,
        public ?string $returnNumber = null,
        public ?string $reason = null,
        public ?string $notes = null,
        public ?string $tenantId = null,
        public ?string $companyId = null,
        public string $returnType = 'credit'
    ) {}
}
