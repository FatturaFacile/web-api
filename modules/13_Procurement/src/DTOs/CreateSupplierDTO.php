<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class CreateSupplierDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $supplierCode,
        public string $companyName,
        public ?string $vatNumber = null,
        public ?string $fiscalCode = null,
        public ?string $categoryId = null,
        public ?string $contactId = null,
        public string $currency = 'EUR',
        public ?string $paymentTermsCode = null,
        public ?string $incoterms = null,
        public float $creditLimit = 0.0,
        public string $rating = 'B',
        public bool $isApprovedVendor = false,
        public array $metadata = [],
        public ?string $tenantId = null,
        public ?string $companyId = null,
        public ?string $creatorUserId = null
    ) {}
}
