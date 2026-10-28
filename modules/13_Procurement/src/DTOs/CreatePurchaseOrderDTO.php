<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class CreatePurchaseOrderDTO
{
    /**
     * @param  array<int, array{description: string, quantity_ordered: float, unit_price: float, uom: string, tax_rate?: float, supplier_catalog_id?: ?string}>  $lines
     * @param  array<string, mixed>  $shippingAddress
     */
    public function __construct(
        public string $supplierId,
        public string $orderDate,
        public string $expectedDeliveryDate,
        public array $lines,
        public ?string $poNumber = null,
        public ?string $requisitionId = null,
        public ?string $contractId = null,
        public ?string $projectId = null,
        public ?string $costCenterId = null,
        public ?string $departmentId = null,
        public ?string $branchId = null,
        public string $orderType = 'standard',
        public string $currency = 'EUR',
        public float $exchangeRate = 1.0,
        public ?string $paymentTermsCode = null,
        public ?string $incoterms = null,
        public array $shippingAddress = [],
        public ?string $notes = null,
        public ?string $tenantId = null,
        public ?string $companyId = null,
        public ?string $creatorUserId = null
    ) {}
}
