<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Commands;

use Illuminate\Support\Carbon;

/**
 * The close-out of a stop by the crew: check-in and check-out with GPS, hours of the members and of the machines, plants and materials taken
 * from the warehouse and the green waste weight. The passage counts on the contract unless the crew says otherwise.
 */
final readonly class LogCrewSiteInterventionCommand
{
    /**
     * @param  array<string, mixed>|null  $checkinGps
     * @param  array<string, mixed>|null  $checkoutGps
     * @param  list<array{member_id: string, hours: float}>  $crewHours
     * @param  list<array{machine: string, hours: float}>  $machineryHours
     * @param  list<array{product_id: string, quantity: float, warehouse_id?: string|null}>  $materials
     */
    public function __construct(
        public string $stopId,
        public ?Carbon $checkinAt,
        public ?Carbon $checkoutAt,
        public ?array $checkinGps = null,
        public ?array $checkoutGps = null,
        public array $crewHours = [],
        public array $machineryHours = [],
        public array $materials = [],
        public ?bool $countsTowardContractPassage = null,
        public ?float $greenWasteWeightKg = null,
        public ?string $wasteCode = null,
        public ?string $compostFacilityId = null,
    ) {}
}
