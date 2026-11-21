<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Contracts\LaborComplianceStrategyInterface;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;

/**
 * Time arithmetic shared by the jurisdictions: the unpaid break sits in the middle of the shift, night, Sunday and holiday
 * minutes are counted in the local time of the establishment (a night shift across a daylight-saving change keeps its real
 * length), and the rest between two shifts is measured from instant to instant.
 */
abstract class AbstractLaborComplianceStrategy implements LaborComplianceStrategyInterface
{
    public function __construct(protected readonly PublicHolidayCalendar $holidays = new PublicHolidayCalendar) {}

    /** Local hour at which the night period starts (it ends at nightEndHour of the next day). */
    abstract protected function nightStartHour(): int;

    abstract protected function nightEndHour(): int;

    protected function timezone(): string
    {
        return $this->jurisdiction()->timezone();
    }

    /**
     * @return array{night: int, sunday: int, holiday: int}
     */
    protected function surchargeMinutes(PlannedShift $shift): array
    {
        $segments = $this->workSegments($shift);
        $timezone = $this->timezone();
        $night = $sunday = $holiday = 0;

        if ($segments === []) {
            return ['night' => 0, 'sunday' => 0, 'holiday' => 0];
        }

        $day = $shift->startAt->setTimezone($timezone)->startOfDay()->subDay();
        $last = $shift->endAt->setTimezone($timezone)->startOfDay();

        for (; $day <= $last; $day = $day->addDay()->startOfDay()) {
            $next = $day->addDay()->startOfDay();
            $night += self::overlapSeconds($day->setTime($this->nightStartHour(), 0)->getTimestamp(), $next->setTime($this->nightEndHour(), 0)->getTimestamp(), $segments);

            if ($day->isoWeekday() === 7) {
                $sunday += self::overlapSeconds($day->getTimestamp(), $next->getTimestamp(), $segments);
            }

            if ($this->holidays->isHoliday($this->jurisdiction(), $day)) {
                $holiday += self::overlapSeconds($day->getTimestamp(), $next->getTimestamp(), $segments);
            }
        }

        return ['night' => intdiv($night, 60), 'sunday' => intdiv($sunday, 60), 'holiday' => intdiv($holiday, 60)];
    }

    /**
     * The worked parts of the shift as [start, end] timestamps: the break is cut out of the middle.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function workSegments(PlannedShift $shift): array
    {
        $start = $shift->startAt->getTimestamp();
        $end = $shift->endAt->getTimestamp();
        $break = $shift->breakMinutes * 60;

        if ($break <= 0) {
            return [[$start, $end]];
        }

        if ($break >= $end - $start) {
            return [];
        }

        $breakStart = intdiv($start + $end - $break, 2);

        return [[$start, $breakStart], [$breakStart + $break, $end]];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $segments
     */
    private static function overlapSeconds(int $from, int $to, array $segments): int
    {
        $total = 0;

        foreach ($segments as [$start, $end]) {
            $total += max(0, min($to, $end) - max($from, $start));
        }

        return $total;
    }

    /**
     * Minutes of rest between the candidate and the closest shift before it and after it (null when there is none).
     *
     * @param  list<PlannedShift>  $others
     * @return array{before: int|null, after: int|null}
     */
    protected function adjacentRest(PlannedShift $candidate, array $others): array
    {
        $before = $after = null;

        foreach ($others as $other) {
            if ($other->endAt <= $candidate->startAt) {
                $rest = intdiv($candidate->startAt->getTimestamp() - $other->endAt->getTimestamp(), 60);
                $before = $before === null ? $rest : min($before, $rest);
            } elseif ($other->startAt >= $candidate->endAt) {
                $rest = intdiv($other->startAt->getTimestamp() - $candidate->endAt->getTimestamp(), 60);
                $after = $after === null ? $rest : min($after, $rest);
            }
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * The rests between consecutive shifts, oldest first.
     *
     * @param  list<PlannedShift>  $shifts
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, minutes: int}>
     */
    protected function gaps(array $shifts): array
    {
        usort($shifts, static fn (PlannedShift $a, PlannedShift $b): int => $a->startAt <=> $b->startAt);
        $gaps = [];
        $coveredUntil = null;

        foreach ($shifts as $shift) {
            if ($coveredUntil !== null && $shift->startAt > $coveredUntil) {
                $gaps[] = ['start' => $coveredUntil, 'end' => $shift->startAt, 'minutes' => intdiv($shift->startAt->getTimestamp() - $coveredUntil->getTimestamp(), 60)];
            }

            $coveredUntil = $coveredUntil === null || $shift->endAt > $coveredUntil ? $shift->endAt : $coveredUntil;
        }

        return $gaps;
    }

    /**
     * Local calendar dates (Y-m-d) on which a shift starts.
     *
     * @param  list<PlannedShift>  $shifts
     * @return array<string, true>
     */
    protected function workingDates(array $shifts): array
    {
        $dates = [];

        foreach ($shifts as $shift) {
            $dates[$shift->localDate($this->timezone())->format('Y-m-d')] = true;
        }

        return $dates;
    }

    /**
     * Length of the run of consecutive working days that includes the candidate day.
     *
     * @param  list<PlannedShift>  $others
     */
    protected function consecutiveWorkingDays(PlannedShift $candidate, array $others): int
    {
        $dates = $this->workingDates([...$others, $candidate]);
        $day = $candidate->localDate($this->timezone());
        $run = 1;

        for ($before = $day->subDay(); isset($dates[$before->format('Y-m-d')]); $before = $before->subDay()) {
            $run++;
        }

        for ($after = $day->addDay(); isset($dates[$after->format('Y-m-d')]); $after = $after->addDay()) {
            $run++;
        }

        return $run;
    }
}
