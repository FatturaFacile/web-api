<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Disposal of an asset, total or partial (percentage of the asset), with proceeds and costs.
 */
final readonly class DisposeAssetDTO
{
    public function __construct(
        public string $assetId,
        public string $disposalType, // 'sale', 'scrap', 'retirement', 'donation', 'theft_loss'
        public string|int|float $proceedsAmount = 0.0,
        public string|int|float $disposalCosts = 0.0,
        public ?string $disposalDate = null,
        public ?string $buyerContactId = null,
        public ?string $reason = null,
        public bool $autoPostToGl = true,
        public string|int|float $disposedPercentage = 100
    ) {}
}
