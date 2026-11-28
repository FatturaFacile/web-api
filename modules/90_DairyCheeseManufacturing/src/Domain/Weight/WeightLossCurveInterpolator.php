<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Weight;

/**
 * Physiological weight loss (calo peso) of a cheese wheel by age: piecewise linear interpolation between the points of the
 * curve, from (0 months, 0 %) to the first point, and flat after the last one.
 */
final class WeightLossCurveInterpolator
{
    /**
     * @param  list<array{age_months: int|float|string, loss_pct: int|float|string}>  $points
     */
    public static function lossPct(array $points, float $ageMonths): float
    {
        $sorted = array_map(static fn (array $p): array => ['age' => (float) $p['age_months'], 'loss' => (float) $p['loss_pct']], $points);
        usort($sorted, static fn (array $a, array $b): int => $a['age'] <=> $b['age']);

        if ($sorted === [] || $ageMonths <= 0) {
            return 0.0;
        }

        $previous = ['age' => 0.0, 'loss' => 0.0];

        foreach ($sorted as $point) {
            if ($ageMonths <= $point['age']) {
                $span = $point['age'] - $previous['age'];

                return $span <= 0.0 ? round($point['loss'], 3) : round($previous['loss'] + ($point['loss'] - $previous['loss']) * (($ageMonths - $previous['age']) / $span), 3);
            }

            $previous = $point;
        }

        return round($previous['loss'], 3);
    }

    /**
     * @param  list<array{age_months: int|float|string, loss_pct: int|float|string}>  $points
     */
    public static function theoreticalWeight(float $initialKg, array $points, float $ageMonths): float
    {
        return round($initialKg * (1 - self::lossPct($points, $ageMonths) / 100), 3);
    }
}
