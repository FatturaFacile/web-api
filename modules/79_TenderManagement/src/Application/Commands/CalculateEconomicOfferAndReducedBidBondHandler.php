<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\TenderManagement\Domain\ValueObjects\OfferInput;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Services\OfferSimulator;
use Modules\TenderManagement\Services\ParticipationService;
use Modules\TenderManagement\Services\VaultService;

final class CalculateEconomicOfferAndReducedBidBondHandler
{
    public function __construct(
        private readonly OfferSimulator $simulator,
        private readonly VaultService $vault,
        private readonly ParticipationService $participations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(CalculateEconomicOfferAndReducedBidBondCommand $command): array
    {
        $participation = TenderParticipation::query()->with('notice')->findOrFail($command->participationId);

        $result = $this->simulator->simulate(
            $participation->notice,
            new OfferInput(
                $command->discountPercentage,
                $command->estimatedTotalCost,
                $command->otherDirectCosts,
                $command->companySafetyCosts,
                $command->companyLaborCosts,
                $command->bidBasePercentage,
                $command->technicalScoreEstimate,
                $command->bestCompetitorDiscount,
                $command->competitorDiscounts,
            ),
            $this->vault->activeDocuments(),
            $command->asOf ?? CarbonImmutable::now(),
        );

        if ($command->persist) {
            $this->participations->storeOffer($participation, $result, $command->bidBasePercentage);
        }

        return $result;
    }
}
