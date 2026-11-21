<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\ValueObjects;

/**
 * Computed deterministic scores and totals for an entire supplier bid.
 */
final readonly class BidScoreResult
{
    /**
     * @param  array<string, LineScoreResult>  $linesByRfqLineId
     */
    public function __construct(
        public string $bidId,
        public string $supplierId,
        public string $totalNetAmount,
        public string $totalLandedCost,
        public string $normalizedLandedCost,
        public string $weightedOverallScore,
        public int $quotedLineCount,
        public bool $coversAllLines,
        public array $linesByRfqLineId,
    ) {}
}
