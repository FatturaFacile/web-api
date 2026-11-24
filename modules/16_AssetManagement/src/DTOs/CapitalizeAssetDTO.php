<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Capitalization of a registered asset: cost, salvage value, dates and depreciation parameters.
 */
final readonly class CapitalizeAssetDTO
{
    public function __construct(
        public string $assetId,
        public string|int|float $capitalizedCost,
        public string|int|float $salvageValue = 0.0,
        public ?string $capitalizationDate = null,
        public ?string $inServiceDate = null,
        public ?string $depreciationMethod = null,
        public ?int $usefulLifeYears = null,
        public bool $autoPostToGl = true,
        public ?string $depreciationStartDate = null,
        public ?string $proRataConvention = null
    ) {}
}
