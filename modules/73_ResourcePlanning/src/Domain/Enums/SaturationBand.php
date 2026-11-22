<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Enums;

/**
 * The colour band of a load percentage: green below 80 %, yellow up to and including 100 %, red above (overload).
 */
enum SaturationBand: string
{
    case None = 'none';
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';

    public const float YellowFrom = 80.0;

    public const float RedAbove = 100.0;

    /** Load reported when something is booked on a period without any net capacity. */
    public const float LoadCap = 999.0;

    public static function fromLoad(float $netHours, float $bookedHours, float $loadPercent): self
    {
        if ($netHours <= 0.0 && $bookedHours <= 0.0) {
            return self::None;
        }

        return match (true) {
            $loadPercent > self::RedAbove => self::Red,
            $loadPercent >= self::YellowFrom => self::Yellow,
            default => self::Green,
        };
    }

    /**
     * Booked share of the net capacity; a booking without capacity is capped at {@see self::LoadCap}.
     */
    public static function loadPercent(float $netHours, float $bookedHours): float
    {
        if ($bookedHours <= 0.0) {
            return 0.0;
        }

        if ($netHours <= 0.0) {
            return self::LoadCap;
        }

        return round(min(self::LoadCap, $bookedHours / $netHours * 100), 2);
    }
}
