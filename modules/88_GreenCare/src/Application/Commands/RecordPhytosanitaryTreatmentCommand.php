<?php

declare(strict_types=1);

namespace Modules\GreenCare\Application\Commands;

use Illuminate\Support\Carbon;
use Modules\GreenCare\Domain\Enums\DosageUnit;

/**
 * An entry of the phytosanitary diary. It is accepted only for an operator who holds a valid license on the treatment date.
 */
final readonly class RecordPhytosanitaryTreatmentCommand
{
    /**
     * @param  array<string, mixed>|null  $weatherConditions
     */
    public function __construct(
        public string $siteId,
        public ?string $routeStopId,
        public Carbon $treatmentDate,
        public string $targetPestOrWeed,
        public string $treatedCropOrSurface,
        public float $treatedAreaSqm,
        public string $commercialProductName,
        public string $registrationNumber,
        public string $activeIngredient,
        public float $dosageUsed,
        public DosageUnit $dosageUnit,
        public ?float $waterVolumeLiters,
        public ?string $warehouseProductId,
        public ?string $warehouseId,
        public string $operatorId,
        public int $reEntryIntervalHours = 0,
        public ?array $weatherConditions = null,
        public ?string $notes = null,
    ) {}
}
