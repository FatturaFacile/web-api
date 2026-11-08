<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class CreatePurchaseOrderDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $lines  description, quantity_ordered, unit_price, uom, tax_rate, supplier_catalog_id, supplier_contract_line_id, unit_weight, expected_delivery_date
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
