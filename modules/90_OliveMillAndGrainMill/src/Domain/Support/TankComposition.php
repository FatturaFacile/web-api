<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Support;

/**
 * Lot traceability of a tank: the kilograms contributed by every lot (a map lot code => kg). Withdrawals take every lot in proportion to its share.
 */
final class TankComposition
{
    /**
     * @param  array<string, float>  $composition
     * @return array<string, float>
     */
    public static function add(array $composition, string $lot, float $kg): array
    {
        $composition[$lot] = round(($composition[$lot] ?? 0.0) + $kg, 3);

        return $composition;
    }

    /**
     * Splits a composition into the part withdrawn and the part left, in proportion to the lots.
     *
     * @param  array<string, float>  $composition
     * @return array{removed: array<string, float>, remaining: array<string, float>}
     */
    public static function withdraw(array $composition, float $kg): array
    {
        $total = array_sum($composition);

        if ($total <= 0.0 || $kg <= 0.0) {
            return ['removed' => [], 'remaining' => $composition];
        }

        if ($kg >= $total - 0.0005) {
            return ['removed' => $composition, 'remaining' => []];
        }

        $removed = [];
        $remaining = [];
        $share = $kg / $total;

        foreach ($composition as $lot => $lotKg) {
            $part = round($lotKg * $share, 3);
            $left = round($lotKg - $part, 3);

            if ($part > 0.0) {
                $removed[$lot] = $part;
            }

            if ($left > 0.0) {
                $remaining[$lot] = $left;
            }
        }

        return ['removed' => $removed, 'remaining' => $remaining];
    }

    /**
     * @param  array<string, float>  $into
     * @param  array<string, float>  $part
     * @return array<string, float>
     */
    public static function merge(array $into, array $part): array
    {
        foreach ($part as $lot => $kg) {
            $into = self::add($into, (string) $lot, $kg);
        }

        return $into;
    }
}
