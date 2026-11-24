<?php

declare(strict_types=1);

namespace App\Application\Procurement\Rfq\Queries;

/**
 * Query to construct the side-by-side bid comparison matrix with sealed-bidding compliance enforcement.
 */
final readonly class GetBidComparisonMatrixQuery
{
    public function __construct(
        public string $rfqId,
    ) {}
}
