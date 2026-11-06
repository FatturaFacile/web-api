<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * How a schedule group rounds a punch for the payroll view. Whatever the mode, the recorded minutes stay as they are.
 */
enum PunchRoundingMode: string
{
    case None = 'none';
    case Nearest = 'nearest';
    case EmployeeFavour = 'employee_favour';
    case EmployerFavour = 'employer_favour';

    /**
     * The punch rounded to a grid of whole minutes counted from local midnight.
     *
     * @param  bool  $isStart  Whether the punch starts a working period: favouring the employee moves a start back and an end forward.
     */
    public function round(CarbonImmutable $punch, int $unitMinutes, bool $isStart): CarbonImmutable
    {
        if ($this === self::None || $unitMinutes <= 0) {
            return $punch;
        }

        $midnight = SwissWorkingTimeRules::toLocal($punch)->startOfDay();
        $elapsed = SwissWorkingTimeRules::minutesBetween($midnight, $punch);
        $lower = intdiv($elapsed, $unitMinutes) * $unitMinutes;
        $upper = $lower === $elapsed ? $lower : $lower + $unitMinutes;

        $minutes = match ($this) {
            self::Nearest => $elapsed - $lower < $upper - $elapsed ? $lower : $upper,
            self::EmployeeFavour => $isStart ? $lower : $upper,
            self::EmployerFavour => $isStart ? $upper : $lower,
        };

        return $midnight->addMinutes($minutes);
    }
}
