<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum SubscriptionInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    /**
     * Move a date forward by `$count` billing intervals without month overflow (Jan 31 + 1 month = Feb 28).
     */
    public function advance(CarbonInterface $date, int $count = 1): CarbonImmutable
    {
        $months = match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Yearly => 12,
        };

        return CarbonImmutable::instance($date)->addMonthsNoOverflow($months * max(1, $count));
    }

    /**
     * First billing date after `$current` on the schedule anchored at `$anchor`.
     * Anchoring avoids month-end drift (Jan 31 -> Feb 28 -> Mar 31, not Mar 28).
     */
    public function nextFromAnchor(CarbonInterface $anchor, CarbonInterface $current, int $count = 1): CarbonImmutable
    {
        for ($step = 1; $step <= 1200; $step++) {
            $candidate = $this->advance($anchor, $step * max(1, $count));

            if ($candidate->gt($current)) {
                return $candidate;
            }
        }

        return $this->advance($current, $count);
    }
}
