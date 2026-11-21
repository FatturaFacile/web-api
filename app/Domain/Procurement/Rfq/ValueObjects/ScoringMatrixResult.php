<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\ValueObjects;

/**
 * Full scoring output across all bids in an RFQ.
 */
final readonly class ScoringMatrixResult
{
    /**
     * @param  array<string, BidScoreResult>  $bidsById
     */
    public function __construct(
        public array $bidsById,
        public ?string $lowestTotalCostBidId,
        public ?string $highestScoreBidId,
    ) {}
}
