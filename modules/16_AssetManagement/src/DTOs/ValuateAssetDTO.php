<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class ValuateAssetDTO
{
    public function __construct(
        public string $assetId,
        public string $valuationType, // 'revaluation' or 'impairment'
        public float $fairValue,
        public float $valueInUse = 0.0,
        public ?string $valuationDate = null,
        public ?string $appraiserName = null,
        public ?string $reason = null,
        public bool $autoPostToGl = true
    ) {}
}
