<?php

declare(strict_types=1);

namespace Modules\Procurement\DTOs;

final readonly class CreateSupplierDTO
{
    public string $supplierCode;

    public ?string $categoryId;

    public ?string $creatorUserId;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $companyName,
        ?string $supplierCode = null,
        public ?string $vatNumber = null,
        public ?string $fiscalCode = null,
        ?string $categoryId = null,
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
        ?string $creatorUserId = null,
        public ?string $supplierCategoryId = null,
        public ?string $userId = null,
        public ?string $notes = null,
        public string $status = 'active'
    ) {
        $this->supplierCode = $supplierCode ?? ('SUP-'.strtoupper(bin2hex(random_bytes(4))));
        $this->categoryId = $categoryId ?? $this->supplierCategoryId;
        $this->creatorUserId = $creatorUserId ?? $this->userId;
    }
}
