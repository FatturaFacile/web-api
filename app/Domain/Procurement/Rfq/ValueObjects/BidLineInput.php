<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\ValueObjects;

/**
 * Input line data passed into BidScoringDomainService.
 */
final readonly class BidLineInput
{
    public function __construct(
        public string $bidLineId,
        public string $rfqLineId,
        public bool $isNoBid,
        public string $quotedQuantity,
        public string $unitPrice,
        public string $discountPercent,
        public int $leadTimeDays,
    ) {}
}
