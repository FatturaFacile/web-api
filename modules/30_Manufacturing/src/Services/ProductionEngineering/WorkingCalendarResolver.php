<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\ProductionEngineering;

use Carbon\CarbonImmutable;
use Modules\HumanResources\Models\HolidayCalendar;
use Modules\HumanResources\Services\WorkingTimeCalculator;
use Modules\Manufacturing\Enums\CalendarDayType;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgWorkingCalendar;
use Modules\Manufacturing\Models\MfgWorkingCalendarShift;
use Modules\Manufacturing\Support\ItalianHolidayCalendar;

/**
 * Resolves what a working calendar offers on a day or over a window: weekly shifts, minus planned shutdowns and the
 * public holidays of the calendar's country and region (Swiss canton via Mod 073, Italian national and patron saint
 * days via Mod 074, plus an inherited HR holiday calendar). A shift that crosses midnight belongs to the day it starts
 * on: a holiday on the following day does not cut it short.
 */
final class WorkingCalendarResolver
{
    /** Days searched when moving the clock to the next working time before giving up. */
    private const int MaxSearchDays = 800;

    /** @var array<string, array<string, string>> holidays by calendar id and year */
    private array $holidayCache = [];

    public function __construct(private readonly WorkingTimeCalculator $workingTime) {}

    /**
     * Public holidays of the calendar in a year as date => name.
     *
     * @return array<string, string>
     */
    public function holidays(MfgWorkingCalendar $calendar, int $year): array
    {
        $key = $calendar->id.':'.$year.':'.$calendar->updated_at?->timestamp;
        if (isset($this->holidayCache[$key])) {
            return $this->holidayCache[$key];
        }

        $holidays = [];
        if (strtoupper($calendar->country_code) === 'IT') {
            $holidays = ItalianHolidayCalendar::forRegion($calendar->region_code, $year);
        } else {
            foreach ($this->workingTime->holidays($calendar->region_code, $year) as $holiday) {
                if ((float) $holiday->dayFraction >= 1) {
                    $holidays[$holiday->date] = $holiday->name;
                }
            }
        }

        if ($calendar->inherit_holiday_calendar_id !== null) {
            $inherited = HolidayCalendar::query()->where('tenant_id', $calendar->tenant_id)->find($calendar->inherit_holiday_calendar_id);
            foreach ((array) $inherited?->getAttribute('holidays') as $entry) {
                $date = is_array($entry) ? (string) ($entry['date'] ?? '') : (string) $entry;
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 && str_starts_with($date, (string) $year)) {
                    $holidays[substr($date, 0, 10)] = is_array($entry) ? (string) ($entry['name'] ?? 'Holiday') : 'Holiday';
                }
            }
        }

        ksort($holidays);

        return $this->holidayCache[$key] = $holidays;
    }

    /**
     * The day of the calendar: its type, the holiday or shutdown reason and the shifts with their clock intervals.
     *
     * @return array{date: string, day_type: CalendarDayType, label: string|null, available_minutes: int, shifts: list<array{shift_code: string, start_at: CarbonImmutable, end_at: CarbonImmutable, net_minutes: int, labor_multiplier: string}>}
     */
    public function resolveDay(MfgWorkingCalendar $calendar, CarbonImmutable $day): array
    {
        $calendar->loadMissing(['shifts', 'closures']);
        $day = $day->setTimezone($calendar->timezone)->startOfDay();
        $date = $day->toDateString();

        $closure = $calendar->closures->first(static fn ($closure): bool => $closure->starts_on->toDateString() <= $date && $closure->ends_on->toDateString() >= $date);
        $holiday = $this->holidays($calendar, $day->year)[$date] ?? null;
        $shifts = $this->shiftsOf($calendar, $day);

        if ($closure !== null) {
            return $this->day($date, CalendarDayType::PlannedShutdown, $closure->reason, []);
        }
        if ($holiday !== null) {
            return $this->day($date, CalendarDayType::Holiday, $holiday, []);
        }
        if ($shifts === [] && $day->isoWeekday() >= 6) {
            return $this->day($date, CalendarDayType::NonWorkingWeekend, null, []);
        }

        return $this->day($date, CalendarDayType::WorkingShift, null, $shifts);
    }

    /**
     * The days of a range, both ends included (at most 366).
     *
     * @return list<array{date: string, day_type: CalendarDayType, label: string|null, available_minutes: int, shifts: list<array{shift_code: string, start_at: CarbonImmutable, end_at: CarbonImmutable, net_minutes: int, labor_multiplier: string}>}>
     */
    public function preview(MfgWorkingCalendar $calendar, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $cursor = $from->setTimezone($calendar->timezone)->startOfDay();
        $last = $to->setTimezone($calendar->timezone)->startOfDay();

        while ($cursor <= $last && count($days) < 366) {
            $days[] = $this->resolveDay($calendar, $cursor);
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * Net available minutes inside the window: shift time (breaks spread evenly over the shift) of the days that are
     * not holidays or shutdowns.
     */
    public function availableMinutes(MfgWorkingCalendar $calendar, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $from = $from->setTimezone($calendar->timezone);
        $to = $to->setTimezone($calendar->timezone);
        $total = 0.0;

        foreach ($this->intervals($calendar, $from->startOfDay()->subDay(), $to->startOfDay()) as $interval) {
            $start = max($interval['start_at'], $from);
            $end = min($interval['end_at'], $to);
            if ($end > $start) {
                $total += $start->diffInSeconds($end, true) / 60 * $interval['rate'];
            }
        }

        return round($total, 3);
    }

    /**
     * The moment at which the given net working minutes, started no earlier than `$from`, are finished.
     */
    public function advance(MfgWorkingCalendar $calendar, CarbonImmutable $from, float $minutes): CarbonImmutable
    {
        $cursor = $from->setTimezone($calendar->timezone);
        $remaining = max(0.0, $minutes);
        $dayStart = $cursor->startOfDay()->subDay();

        for ($offset = 0; $offset < self::MaxSearchDays; $offset++) {
            $day = $dayStart->addDays($offset);
            foreach ($this->intervals($calendar, $day, $day) as $interval) {
                $start = max($interval['start_at'], $cursor);
                if ($start >= $interval['end_at']) {
                    continue;
                }
                $wallMinutes = $start->diffInSeconds($interval['end_at'], true) / 60;
                $net = $wallMinutes * $interval['rate'];
                if ($remaining <= $net + 1e-9) {
                    return $start->addSeconds((int) round(($remaining / $interval['rate']) * 60));
                }
                $remaining -= $net;
                $cursor = $interval['end_at'];
            }
        }

        throw new ManufacturingOperationException("Working calendar [{$calendar->code}] has no working time to schedule the operations.", 'working_calendar_id');
    }

    /**
     * Shift intervals of the days from `$firstDay` to `$lastDay` that are working days, ordered by start.
     *
     * @return list<array{start_at: CarbonImmutable, end_at: CarbonImmutable, rate: float}>
     */
    private function intervals(MfgWorkingCalendar $calendar, CarbonImmutable $firstDay, CarbonImmutable $lastDay): array
    {
        $intervals = [];
        for ($day = $firstDay; $day <= $lastDay; $day = $day->addDay()) {
            foreach ($this->resolveDay($calendar, $day)['shifts'] as $shift) {
                $wall = $shift['start_at']->diffInSeconds($shift['end_at'], true) / 60;
                if ($wall > 0 && $shift['net_minutes'] > 0) {
                    $intervals[] = ['start_at' => $shift['start_at'], 'end_at' => $shift['end_at'], 'rate' => $shift['net_minutes'] / $wall];
                }
            }
        }

        usort($intervals, static fn (array $left, array $right): int => $left['start_at'] <=> $right['start_at']);

        return $intervals;
    }

    /**
     * @return list<array{shift_code: string, start_at: CarbonImmutable, end_at: CarbonImmutable, net_minutes: int, labor_multiplier: string}>
     */
    private function shiftsOf(MfgWorkingCalendar $calendar, CarbonImmutable $day): array
    {
        $shifts = [];
        /** @var MfgWorkingCalendarShift $shift */
        foreach ($calendar->shifts->where('day_of_week', $day->isoWeekday()) as $shift) {
            $start = $day->setTime(intdiv(MfgWorkingCalendarShift::minuteOfDay($shift->start_time), 60), MfgWorkingCalendarShift::minuteOfDay($shift->start_time) % 60);
            $end = $day->setTime(intdiv(MfgWorkingCalendarShift::minuteOfDay($shift->end_time), 60), MfgWorkingCalendarShift::minuteOfDay($shift->end_time) % 60);
            if ($shift->crosses_midnight || $end <= $start) {
                $end = $end->addDay();
            }
            $shifts[] = ['shift_code' => $shift->shift_code, 'start_at' => $start, 'end_at' => $end, 'net_minutes' => $shift->net_available_minutes, 'labor_multiplier' => (string) $shift->labor_multiplier];
        }

        usort($shifts, static fn (array $left, array $right): int => $left['start_at'] <=> $right['start_at']);

        return $shifts;
    }

    /**
     * @param  list<array{shift_code: string, start_at: CarbonImmutable, end_at: CarbonImmutable, net_minutes: int, labor_multiplier: string}>  $shifts
     * @return array{date: string, day_type: CalendarDayType, label: string|null, available_minutes: int, shifts: list<array{shift_code: string, start_at: CarbonImmutable, end_at: CarbonImmutable, net_minutes: int, labor_multiplier: string}>}
     */
    private function day(string $date, CalendarDayType $type, ?string $label, array $shifts): array
    {
        return [
            'date' => $date,
            'day_type' => $type,
            'label' => $label,
            'available_minutes' => array_sum(array_column($shifts, 'net_minutes')),
            'shifts' => $shifts,
        ];
    }
}
