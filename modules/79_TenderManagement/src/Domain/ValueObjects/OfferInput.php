<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\ValueObjects;

/**
 * The inputs of an economic offer simulation.
 */
final readonly class OfferInput
{
    /**
     * @param  list<string>  $competitorDiscounts  Discount percentages of the competitors (market intelligence).
     */
    public function __construct(
        public string $discountPercentage,
        public ?string $estimatedTotalCost,
        public string $otherDirectCosts,
        public ?string $companySafetyCosts,
        public ?string $companyLaborCosts,
        public string $bidBasePercentage,
        public ?string $technicalScoreEstimate,
        public ?string $bestCompetitorDiscount,
        public array $competitorDiscounts,
    ) {}
}
