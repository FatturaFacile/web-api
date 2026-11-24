<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Carbon\Carbon;
use Modules\Workflow\Contracts\BusinessCalendarCalculatorInterface;
use Modules\Workflow\Models\BusinessCalendar;

final class BusinessCalendarCalculationService implements BusinessCalendarCalculatorInterface
{
    public function calculateDeadline(Carbon $startTime, int $businessHours, ?BusinessCalendar $calendar = null): Carbon
    {
        $cal = $calendar ?? $this->resolveDefaultCalendar();

        if ($cal === null || $businessHours <= 0) {
            return $startTime->copy()->addHours($businessHours > 0 ? $businessHours : 48);
        }

        $currentTime = $startTime->copy()->setTimezone($cal->timezone);
        $remainingMinutes = $businessHours * 60;

        $holidays = $cal->holidays()
            ->pluck('holiday_date')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10))
            ->flip()
            ->all();

        $startParts = explode(':', (string) ($cal->work_hours_start ?? '08:30:00'));
        $endParts = explode(':', (string) ($cal->work_hours_end ?? '17:30:00'));
        $workStartMinutes = ((int) $startParts[0]) * 60 + ((int) $startParts[1]);
        $workEndMinutes = ((int) $endParts[0]) * 60 + ((int) $endParts[1]);
        $workDayDuration = max(60, $workEndMinutes - $workStartMinutes);

        // Advance to a valid business minute if currently outside
        $currentTime = $this->advanceToNextBusinessWindow($currentTime, $cal, $holidays, $workStartMinutes, $workEndMinutes);

        while ($remainingMinutes > 0) {
            $currentDayMinutes = $currentTime->hour * 60 + $currentTime->minute;
            $availableMinutesToday = max(0, $workEndMinutes - $currentDayMinutes);

            if ($remainingMinutes <= $availableMinutesToday) {
                $currentTime->addMinutes($remainingMinutes);
                $remainingMinutes = 0;
            } else {
                $remainingMinutes -= $availableMinutesToday;
                // Move to start of next business day
                $currentTime->addDay()->setTime((int) $startParts[0], (int) $startParts[1], 0);
                $currentTime = $this->advanceToNextBusinessWindow($currentTime, $cal, $holidays, $workStartMinutes, $workEndMinutes);
            }
        }

        return $currentTime->setTimezone('UTC');
    }

    public function isBusinessTime(Carbon $datetime, ?BusinessCalendar $calendar = null): bool
    {
        $cal = $calendar ?? $this->resolveDefaultCalendar();
        if ($cal === null) {
            return ! $datetime->isWeekend();
        }

        $localTime = $datetime->copy()->setTimezone($cal->timezone);

        if (! $this->isWorkingDay($localTime, $cal)) {
            return false;
        }

        $dateString = $localTime->toDateString();
        $isHoliday = $cal->holidays()->where('holiday_date', $dateString)->exists();
        if ($isHoliday) {
            return false;
        }

        $currentMinutes = $localTime->hour * 60 + $localTime->minute;
        $startParts = explode(':', (string) ($cal->work_hours_start ?? '08:30:00'));
        $endParts = explode(':', (string) ($cal->work_hours_end ?? '17:30:00'));
        $workStartMinutes = ((int) $startParts[0]) * 60 + ((int) $startParts[1]);
        $workEndMinutes = ((int) $endParts[0]) * 60 + ((int) $endParts[1]);

        return $currentMinutes >= $workStartMinutes && $currentMinutes < $workEndMinutes;
    }

    /**
     * @param  array<string, int>  $holidays
     */
    private function advanceToNextBusinessWindow(
        Carbon $time,
        BusinessCalendar $cal,
        array $holidays,
        int $workStartMinutes,
        int $workEndMinutes
    ): Carbon {
        $startParts = explode(':', (string) ($cal->work_hours_start ?? '08:30:00'));
        $safetyLimit = 365;

        while ($safetyLimit-- > 0) {
            $isWorkDay = $this->isWorkingDay($time, $cal);
            $isHoliday = isset($holidays[$time->toDateString()]);

            if ($isWorkDay && ! $isHoliday) {
                $currentMinutes = $time->hour * 60 + $time->minute;
                if ($currentMinutes < $workStartMinutes) {
                    return $time->setTime((int) $startParts[0], (int) $startParts[1], 0);
                }
                if ($currentMinutes < $workEndMinutes) {
                    return $time;
                }
            }

            // Outside hours or holiday/weekend -> move to next morning
            $time->addDay()->setTime((int) $startParts[0], (int) $startParts[1], 0);
        }

        return $time;
    }

    private function isWorkingDay(Carbon $time, BusinessCalendar $cal): bool
    {
        return match ($time->dayOfWeekIso) {
            1 => $cal->work_monday,
            2 => $cal->work_tuesday,
            3 => $cal->work_wednesday,
            4 => $cal->work_thursday,
            5 => $cal->work_friday,
            6 => $cal->work_saturday,
            7 => $cal->work_sunday,
            default => false,
        };
    }

    private function resolveDefaultCalendar(): ?BusinessCalendar
    {
        return BusinessCalendar::where('is_default', true)->first();
    }
}
