<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class DisposeAssetDTO
{
    public function __construct(
        public string $assetId,
        public string $disposalType, // 'sale', 'scrap', 'retirement', 'donation', 'theft_loss'
        public float $proceedsAmount = 0.0,
        public float $disposalCosts = 0.0,
        public ?string $disposalDate = null,
        public ?string $buyerContactId = null,
        public ?string $reason = null,
        public bool $autoPostToGl = true
    ) {}
}
