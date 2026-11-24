<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\ValueObjects;

/**
 * Input bid header data passed into BidScoringDomainService.
 */
final readonly class BidHeaderInput
{
    /**
     * @param  list<BidLineInput>  $lines
     */
    public function __construct(
        public string $bidId,
        public string $supplierId,
        public string $currencyCode,
        public string $exchangeRateToRfq,
        public string $shippingCost,
        public float $supplierRatingScore,
        public array $lines,
    ) {}
}
