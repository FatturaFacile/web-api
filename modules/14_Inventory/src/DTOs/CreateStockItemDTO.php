<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

final readonly class CreateStockItemDTO
{
    public function __construct(
        public string $categoryId,
        public string $sku,
        public string $name,
        public string $uom = 'PCE',
        public ?string $description = null,
        public ?string $barcodeEan13 = null,
        public ?string $barcodeCustom = null,
        public string $trackingType = 'none',
        public float $standardCost = 0.0,
        public float $movingAverageCost = 0.0,
        public ?float $weightKg = null,
        public bool $isActive = true,
        public ?string $companyId = null
    ) {}
}
