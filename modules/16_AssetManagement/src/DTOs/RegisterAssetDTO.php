<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class RegisterAssetDTO
{
    /**
     * @param  array<string, mixed>  $specifications
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $name,
        public string $categoryId,
        public float $acquisitionCost,
        public string $acquisitionType = 'purchase',
        public ?string $assetClassId = null,
        public ?string $locationId = null,
        public ?string $parentAssetId = null,
        public ?string $custodianUserId = null,
        public ?string $projectId = null,
        public ?string $supplierId = null,
        public ?string $purchaseOrderId = null,
        public ?string $purchaseReceiptId = null,
        public ?string $inventoryItemId = null,
        public ?string $assetNumber = null,
        public ?string $barcodeTag = null,
        public ?string $serialNumber = null,
        public ?string $description = null,
        public ?string $acquisitionDate = null,
        public float $salvageValue = 0.0,
        public ?string $depreciationMethod = null,
        public ?int $usefulLifeYears = null,
        public float $totalExpectedUnits = 0.0,
        public float $replacementCost = 0.0,
        public string $currency = 'EUR',
        public string $condition = 'new',
        public array $specifications = [],
        public array $metadata = []
    ) {}
}
