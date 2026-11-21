<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\ValueObjects;

/**
 * Computed deterministic scores and comparison flags for a single supplier bid line.
 */
final readonly class LineScoreResult
{
    public function __construct(
        public string $bidId,
        public string $bidLineId,
        public string $rfqLineId,
        public bool $isNoBid,
        public string $netUnitPrice,
        public string $lineTotalNet,
        public string $normalizedNetUnitPrice,
        public string $normalizedLineTotalNet,
        public int $leadTimeDays,
        public string $priceScore,
        public string $leadTimeScore,
        public string $supplierRatingScore,
        public string $lineWeightedScore,
        public bool $isLowestPrice,
        public bool $isFastestDelivery,
        public bool $isHighestScore,
    ) {}
}
