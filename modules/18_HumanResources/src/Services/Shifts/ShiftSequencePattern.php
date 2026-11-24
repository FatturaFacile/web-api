<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

use Carbon\CarbonImmutable;

/**
 * How a sequence turns its steps into days. A rotation runs through its steps from the start of the assignment; a weekly
 * pattern takes one step per weekday (Monday first) and leaves the excluded days free; a manual sequence generates nothing.
 */
enum ShiftSequencePattern: string
{
    case Rotation = 'rotation';
    case WeeklyWithoutSunday = 'weekly_without_sunday';
    case WeeklyWithoutWeekend = 'weekly_without_weekend';
    case Manual = 'manual';

    /**
     * The step of a day, or null when the pattern leaves the day free.
     *
     * @param  int  $stepCount  The number of steps of the sequence.
     * @param  int  $elapsedDays  The days since the start of the assignment, offset included.
     */
    public function stepIndex(CarbonImmutable $day, int $stepCount, int $elapsedDays): ?int
    {
        if ($stepCount === 0) {
            return null;
        }

        $weekday = ($day->dayOfWeek + 6) % 7;

        return match ($this) {
            self::Rotation => $elapsedDays % $stepCount,
            self::WeeklyWithoutSunday => $weekday === 6 ? null : $weekday % $stepCount,
            self::WeeklyWithoutWeekend => $weekday >= 5 ? null : $weekday % $stepCount,
            self::Manual => null,
        };
    }
}
