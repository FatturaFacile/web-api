<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Milk;

use InvalidArgumentException;

/**
 * Liters ↔ kg conversion of raw cow milk (density about 1.032 kg/l at 20 °C).
 */
final class MilkConversion
{
    public const float DefaultDensity = 1.032;

    public static function kg(float $liters, float $density = self::DefaultDensity): float
    {
        if ($liters < 0 || $density <= 0) {
            throw new InvalidArgumentException('Liters must not be negative and the density must be positive.');
        }

        return round($liters * $density, 3);
    }

    public static function liters(float $kg, float $density = self::DefaultDensity): float
    {
        if ($kg < 0 || $density <= 0) {
            throw new InvalidArgumentException('Kilograms must not be negative and the density must be positive.');
        }

        return round($kg / $density, 3);
    }
}
