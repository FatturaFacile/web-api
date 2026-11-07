<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\HumanResources\Models\HolidayCalendar;
use Modules\ServiceManagement\Models\BusinessHoursCalendar;

/**
 * SLA clock on business hours. Working windows are wall-clock times of the calendar's time zone, so a day on which the
 * clocks change counts the real minutes of its windows. Holidays come from the calendar itself and from the HR holiday
 * calendar it points to. Without a calendar the clock runs around the clock.
 */
final class BusinessHoursClock
{
    /**
     * Upper bound of days walked, so a calendar without working windows cannot loop forever.
     */
    private const int MaxDays = 3660;

    /** @var array<string, string> */
    private const array DayKeys = [
        'mon' => 'mon', 'monday' => 'mon', '1' => 'mon',
        'tue' => 'tue', 'tuesday' => 'tue', '2' => 'tue',
        'wed' => 'wed', 'wednesday' => 'wed', '3' => 'wed',
        'thu' => 'thu', 'thursday' => 'thu', '4' => 'thu',
        'fri' => 'fri', 'friday' => 'fri', '5' => 'fri',
        'sat' => 'sat', 'saturday' => 'sat', '6' => 'sat',
        'sun' => 'sun', 'sunday' => 'sun', '7' => 'sun', '0' => 'sun',
    ];

    public function addBusinessMinutes(DateTimeInterface $start, int $minutes, ?BusinessHoursCalendar $calendar): CarbonImmutable
    {
        $start = CarbonImmutable::instance($start)->utc();

        if ($calendar === null || $minutes <= 0) {
            return $start->addMinutes(max(0, $minutes));
        }

        $remaining = $minutes * 60;

        foreach ($this->windowsFrom($start, $calendar) as [$windowStart, $windowEnd]) {
            $from = $windowStart->greaterThan($start) ? $windowStart : $start;
            $available = $windowEnd->getTimestamp() - $from->getTimestamp();

            if ($available <= 0) {
                continue;
            }

            if ($available >= $remaining) {
                return $from->addSeconds($remaining)->utc();
            }

            $remaining -= $available;
        }

        return $start->addMinutes($minutes);
    }

    public function businessMinutesBetween(DateTimeInterface $from, DateTimeInterface $to, ?BusinessHoursCalendar $calendar): int
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        if ($calendar === null) {
            return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
        }

        $seconds = 0;

        foreach ($this->windowsFrom($from, $calendar) as [$windowStart, $windowEnd]) {
            if ($windowStart->greaterThanOrEqualTo($to)) {
                break;
            }

            $start = $windowStart->greaterThan($from) ? $windowStart : $from;
            $end = $windowEnd->lessThan($to) ? $windowEnd : $to;
            $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
        }

        return intdiv($seconds, 60);
    }

    /**
     * Working windows, in UTC, from the day of the given instant onwards.
     *
     * @return iterable<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windowsFrom(CarbonImmutable $instant, BusinessHoursCalendar $calendar): iterable
    {
        $timezone = $calendar->timezone ?: 'UTC';
        $schedule = $this->schedule(is_array($calendar->weekly_schedule) ? $calendar->weekly_schedule : []);
        $holidays = $this->holidays($calendar);

        if ($schedule === []) {
            return;
        }

        $day = $instant->setTimezone($timezone)->startOfDay();

        for ($index = 0; $index < self::MaxDays; $index++, $day = $day->addDay()) {
            $date = $day->format('Y-m-d');

            if (isset($holidays[$date]) || isset($holidays[$day->format('m-d')])) {
                continue;
            }

            foreach ($schedule[strtolower($day->format('D'))] ?? [] as [$opens, $closes]) {
                $windowStart = CarbonImmutable::parse("{$date} {$opens}", $timezone)->utc();
                $windowEnd = CarbonImmutable::parse("{$date} {$closes}", $timezone)->utc();

                if ($windowEnd->lessThanOrEqualTo($instant) || $windowEnd->lessThanOrEqualTo($windowStart)) {
                    continue;
                }

                yield [$windowStart, $windowEnd];
            }
        }
    }

    /**
     * Normalizes the weekly schedule: {"mon": [["08:00","12:00"],["13:00","17:00"]]}, {"monday": {"start": "08:00",
     * "end": "18:00"}} and {"1": ["08:00","18:00"]} are all accepted.
     *
     * @param  array<string|int, mixed>  $raw
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function schedule(array $raw): array
    {
        $schedule = [];

        foreach ($raw as $key => $windows) {
            $day = self::DayKeys[strtolower((string) $key)] ?? null;

            if ($day === null || ! is_array($windows) || $windows === []) {
                continue;
            }

            if (isset($windows['start'], $windows['end'])) {
                $windows = [[$windows['start'], $windows['end']]];
            } elseif (is_string($windows[0] ?? null)) {
                $windows = [$windows];
            }

            foreach ($windows as $window) {
                $opens = $window['start'] ?? $window[0] ?? null;
                $closes = $window['end'] ?? $window[1] ?? null;

                if (is_string($opens) && is_string($closes)) {
                    $schedule[$day][] = [$opens, $closes];
                }
            }
        }

        foreach ($schedule as &$windows) {
            usort($windows, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));
        }

        return $schedule;
    }

    /**
     * Holiday dates (Y-m-d, or m-d for a holiday on the same day every year) of the calendar and of the HR calendar.
     *
     * @return array<string, true>
     */
    private function holidays(BusinessHoursCalendar $calendar): array
    {
        $entries = is_array($calendar->holidays) ? $calendar->holidays : [];

        if ($calendar->hr_holiday_calendar_id !== null) {
            $hrCalendar = HolidayCalendar::query()->find($calendar->hr_holiday_calendar_id);
            $entries = [...$entries, ...(is_array($hrCalendar?->holidays) ? $hrCalendar->holidays : [])];
        }

        $dates = [];

        foreach ($entries as $entry) {
            $date = is_array($entry) ? ($entry['date'] ?? null) : $entry;

            if (is_string($date) && preg_match('/^(\d{4}-)?\d{2}-\d{2}$/', $date) === 1) {
                $dates[$date] = true;
            }
        }

        return $dates;
    }
}
