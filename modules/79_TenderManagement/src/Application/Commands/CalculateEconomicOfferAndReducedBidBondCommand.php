<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Simulates the economic offer of a dossier and its provisional bid bond, optionally storing the outcome on the dossier.
 */
final readonly class CalculateEconomicOfferAndReducedBidBondCommand
{
    /**
     * @param  list<string>  $competitorDiscounts
     */
    public function __construct(
        public string $participationId,
        public string $discountPercentage,
        public ?string $estimatedTotalCost,
        public string $otherDirectCosts,
        public ?string $companySafetyCosts,
        public ?string $companyLaborCosts,
        public string $bidBasePercentage,
        public ?string $technicalScoreEstimate = null,
        public ?string $bestCompetitorDiscount = null,
        public array $competitorDiscounts = [],
        public bool $persist = false,
        public ?CarbonImmutable $asOf = null,
    ) {}
}
