<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum FeeFrequency: string
{
    case OneOff = 'one_off';
    case Monthly = 'monthly';
    case Bimonthly = 'bimonthly';
    case Quarterly = 'quarterly';
    case FourMonthly = 'four_monthly';
    case HalfYearly = 'half_yearly';
    case Annual = 'annual';
    case PerAttendance = 'per_attendance';

    /**
     * Months between two due dates; null when the fee has no calendar.
     */
    public function months(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Bimonthly => 2,
            self::Quarterly => 3,
            self::FourMonthly => 4,
            self::HalfYearly => 6,
            self::Annual => 12,
            self::OneOff, self::PerAttendance => null,
        };
    }
}
