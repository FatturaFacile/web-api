<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class ProcessPurchaseReturnDTO
{
    /**
     * @param  array<int, array{purchase_order_line_id: string, quantity_returned: float, unit_price: float, reason?: ?string}>  $lines
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
        public ?string $companyId = null
    ) {}
}
