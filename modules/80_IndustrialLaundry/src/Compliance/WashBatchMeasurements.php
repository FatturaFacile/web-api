<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Compliance;

use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;
use Modules\IndustrialLaundry\Models\WashProgram;

/**
 * What the wash line measured for a batch, to compare with the program it ran.
 */
final readonly class WashBatchMeasurements
{
    public function __construct(
        public WashProgram $program,
        public RabcRiskZone $riskZone,
        public ?string $temperatureC,
        public ?int $holdMinutes,
        public ?string $chemicalDosingMlPerKg,
        public ?string $cfuPer25Cm2,
    ) {}
}
