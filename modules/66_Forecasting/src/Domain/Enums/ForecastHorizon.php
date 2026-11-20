<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Enums;

use InvalidArgumentException;

/**
 * Length of a liquidity projection.
 */
enum ForecastHorizon: string
{
    case Weeks13 = 'weeks_13';
    case Months12 = 'months_12';
    case Custom = 'custom';

    public const int MaxCustomDays = 730;

    public static function fromDays(int $days): self
    {
        if ($days < 1 || $days > self::MaxCustomDays) {
            throw new InvalidArgumentException('The horizon must be between 1 and '.self::MaxCustomDays.' days.');
        }

        return match ($days) {
            91 => self::Weeks13,
            365 => self::Months12,
            default => self::Custom,
        };
    }

    /**
     * Days of the horizon; a custom horizon needs its own length.
     */
    public function days(?int $customDays = null): int
    {
        return match ($this) {
            self::Weeks13 => 91,
            self::Months12 => 365,
            self::Custom => $customDays ?? throw new InvalidArgumentException('A custom horizon needs its length in days.'),
        };
    }
}
