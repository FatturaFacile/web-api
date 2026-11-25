<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Services;

use Modules\IndustrialLaundry\Exceptions\IndustrialLaundryException;
use Modules\IndustrialLaundry\Support\Money;

/**
 * Net weight of the linen in a roll cage: gross weight minus the tare of the cage.
 */
final class RollWeightCalculator
{
    /**
     * @param  string|null  $maxGross  The heaviest gross weight the cage may be weighed at (its own limit, else the configured one).
     */
    public function net(string|int|float $gross, string|int|float $tare, ?string $maxGross = null): string
    {
        $gross = Money::weight($gross);
        $tare = Money::weight($tare);

        if (Money::compare($tare, '0') < 0 || Money::compare($gross, '0') < 0) {
            throw new IndustrialLaundryException('weight_negative');
        }

        if (Money::compare($gross, $tare) < 0) {
            throw new IndustrialLaundryException('gross_below_tare', 422, ['gross' => $gross, 'tare' => $tare]);
        }

        $ceiling = $maxGross ?? (string) config('industrial_laundry.roll_cage.max_gross_weight_kg', '600');

        if (Money::compare($gross, Money::weight($ceiling)) > 0) {
            throw new IndustrialLaundryException('gross_above_limit', 422, ['gross' => $gross, 'limit' => Money::weight($ceiling)]);
        }

        return Money::weight(Money::sub($gross, $tare));
    }
}
