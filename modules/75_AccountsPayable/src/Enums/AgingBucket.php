<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Enums;

/**
 * The aging buckets of the payables, by days past the due date. The keys are shared by the summary, the detail, the
 * exports and every client.
 */
enum AgingBucket: string
{
    case Current = 'current';
    case Days1To30 = '1_30';
    case Days31To60 = '31_60';
    case Days61To90 = '61_90';
    case Over90 = 'over_90';

    public static function forDaysPastDue(int $days): self
    {
        return match (true) {
            $days <= 0 => self::Current,
            $days <= 30 => self::Days1To30,
            $days <= 60 => self::Days31To60,
            $days <= 90 => self::Days61To90,
            default => self::Over90,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $bucket): string => $bucket->value, self::cases());
    }
}
