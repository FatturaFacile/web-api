<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Domain\Dscr;

use Modules\CrisisMonitoring\Domain\Enums\TrafficLight;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The DSCR bands: red below the critical threshold (1.0), yellow up to the warning threshold (1.2), green above.
 */
final readonly class DscrBands
{
    public function __construct(public string $critical = '1.00', public string $warning = '1.20') {}

    public function classify(string $ratio): TrafficLight
    {
        if (Decimal::compare($ratio, $this->critical) < 0) {
            return TrafficLight::Red;
        }

        return Decimal::compare($ratio, $this->warning) <= 0 ? TrafficLight::Yellow : TrafficLight::Green;
    }
}
