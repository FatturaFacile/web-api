<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Direct capital improvement or extraordinary capitalized maintenance applied to an asset.
 */
final readonly class RecordAssetImprovementDTO
{
    public function __construct(
        public string $assetId,
        public string|int|float $amount,
        public string $description,
        public ?string $improvementDate = null,
        public ?string $supplierId = null,
        public ?string $purchaseInvoiceId = null,
        public ?string $purchaseInvoiceNumber = null,
        public int $extendsUsefulLifeMonths = 0,
        public bool $affectsTaxBook = true,
        public bool $autoPostToGl = true,
    ) {}
}
