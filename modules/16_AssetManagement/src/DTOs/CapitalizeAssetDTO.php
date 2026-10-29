<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class CapitalizeAssetDTO
{
    public function __construct(
        public string $assetId,
        public float $capitalizedCost,
        public float $salvageValue = 0.0,
        public ?string $capitalizationDate = null,
        public ?string $inServiceDate = null,
        public ?string $depreciationMethod = null,
        public ?int $usefulLifeYears = null,
        public bool $autoPostToGl = true
    ) {}
}
