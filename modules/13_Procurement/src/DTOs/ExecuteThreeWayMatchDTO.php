<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class ExecuteThreeWayMatchDTO
{
    public function __construct(
        public string $purchaseOrderId,
        public string $purchaseReceiptId,
        public string $passiveInvoiceId,
        public float $invoiceTotalAmount,
        public ?string $approvedByUserId = null,
        public ?string $tenantId = null,
        public ?string $companyId = null
    ) {}
}
