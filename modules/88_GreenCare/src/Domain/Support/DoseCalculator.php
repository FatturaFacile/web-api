<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Support;

use Modules\GreenCare\Domain\Enums\DosageUnit;

/**
 * Quantity of product that a treatment uses, in the base unit of the warehouse item: litres for ml/hl and l/ha, kilograms for g/100sqm.
 */
final class DoseCalculator
{
    /**
     * @param  float  $dosage  dose in `$unit`
     * @param  float  $areaSqm  treated area
     * @param  float|null  $waterLiters  spray volume (needed by ml/hl: the dose is per 100 litres of mixture)
     */
    public static function productQuantity(DosageUnit $unit, float $dosage, float $areaSqm, ?float $waterLiters): float
    {
        $quantity = match ($unit) {
            DosageUnit::MlPerHl => $waterLiters === null ? 0.0 : $dosage * ($waterLiters / 100) / 1000,
            DosageUnit::GPer100Sqm => $dosage * ($areaSqm / 100) / 1000,
            DosageUnit::LPerHa => $dosage * ($areaSqm / 10000),
        };

        return round($quantity, 4);
    }

    public static function needsWaterVolume(DosageUnit $unit): bool
    {
        return $unit === DosageUnit::MlPerHl;
    }
}
