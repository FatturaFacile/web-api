<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

/**
 * A rotation pattern: how many days in a row an employee works and rests.
 */
enum RotationPattern: string
{
    /** Five working days (Monday to Friday) and the weekend off; the shift type changes every week. */
    case FiveByTwo = '5x2';

    /** Six working days, six days off (a 12-day cycle, the two halves of the team offset by half a cycle); the shift type changes every cycle. */
    case SixBySix = '6x6';

    public function cycleDays(): int
    {
        return match ($this) {
            self::FiveByTwo => 7,
            self::SixBySix => 12,
        };
    }

    public function workDays(): int
    {
        return match ($this) {
            self::FiveByTwo => 5,
            self::SixBySix => 6,
        };
    }
}
