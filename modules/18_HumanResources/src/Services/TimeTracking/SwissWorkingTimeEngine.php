<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;

/**
 * Evaluates clock events against the Swiss Labour Act: working time, breaks (art. 15), daily span (art. 10), daily rest
 * (art. 15a), night and Sunday work (art. 16-18), legal weekly maximum and statutory overtime (art. 9 and 12), and the
 * contractual time balance. Everything is evaluated in Europe/Zurich local time.
 */
final class SwissWorkingTimeEngine
{
    public static function evaluate(WorkingTimeContext $context): WorkingTimeReport
    {
        $profile = $context->profile;
        $legalMaximum = (float) $profile->legalMaxWeeklyHours <= 0 ? 45 : $profile->legalMaxWeeklyHours;

        $report = new WorkingTimeReport(
            employeeId: $profile->employeeId,
            employeeName: $profile->employeeName,
            recordingMode: $profile->mode,
            from: SwissWorkingTimeRules::toLocal($context->from)->startOfDay(),
            to: SwissWorkingTimeRules::toLocal($context->to)->startOfDay(),
            statutoryOvertimeBeforeMinutes: $context->statutoryOvertimeBeforeMinutes,
            statutoryOvertimeCapMinutes: SwissWorkingTimeRules::yearlyOvertimeCapMinutes($legalMaximum),
        );

        [$intervals, $issues] = self::buildIntervals($context->events, SwissWorkingTimeRules::toLocal($context->now));
        $isSimplified = $profile->mode === TimeRecordingMode::Simplified;

        for ($day = $report->from; $day <= $report->to; $day = $day->addDay()) {
            $result = $isSimplified
                ? self::evaluateSimplifiedDay($day, $context->dailyRecords)
                : self::evaluateDetailedDay($day, $intervals, $issues);
            self::applyTarget($result, $context);
            $report->days[] = $result;
        }

        self::addWeeks($report, $legalMaximum, $profile->workDaysPerWeek);
        self::addPeriodIssues($report, $profile);

        return $report;
    }

    /**
     * @param  array<int, ClockEntry>  $events
     * @return array{0: array<int, WorkInterval>, 1: array<string, array<int, string>>}
     */
    private static function buildIntervals(array $events, CarbonImmutable $now): array
    {
        $valid = array_values(array_filter($events, static fn (ClockEntry $event): bool => ! $event->isDeleted));
        usort($valid, static fn (ClockEntry $left, ClockEntry $right): int => $left->occurredAt <=> $right->occurredAt);

        $intervals = [];
        $issues = [];
        $open = null;

        foreach ($valid as $event) {
            $timestamp = SwissWorkingTimeRules::toLocal($event->occurredAt);

            if ($event->eventType->startsWork()) {
                if ($open !== null) {
                    $issues[$open->format('Y-m-d')][] = 'Entrata senza uscita prima di una nuova entrata';
                }

                $open = $timestamp;
            } elseif ($open !== null) {
                $intervals[] = new WorkInterval($open, $timestamp);
                $open = null;
            } else {
                $issues[$timestamp->format('Y-m-d')][] = 'Uscita senza entrata';
            }
        }

        if ($open !== null && $open->format('Y-m-d') < $now->format('Y-m-d')) {
            $issues[$open->format('Y-m-d')][] = 'Timbratura di uscita mancante';
        }

        return [$intervals, $issues];
    }

    /**
     * @param  array<int, WorkInterval>  $intervals
     * @param  array<string, array<int, string>>  $issues
     */
    private static function evaluateDetailedDay(CarbonImmutable $day, array $intervals, array $issues): WorkingDayResult
    {
        $result = new WorkingDayResult($day);
        $date = $day->format('Y-m-d');
        $today = array_values(array_filter($intervals, static fn (WorkInterval $interval): bool => $interval->start->format('Y-m-d') === $date));
        $result->issues = $issues[$date] ?? [];

        if ($today === []) {
            return $result;
        }

        $result->firstIn = $today[0]->start;
        $result->lastOut = $today[count($today) - 1]->end;

        foreach ($today as $index => $interval) {
            $result->workedMinutes += $interval->minutes();
            $result->nightMinutes += SwissWorkingTimeRules::nightMinutes($interval->start, $interval->end);
            $result->sundayWork = $result->sundayWork || SwissWorkingTimeRules::isSundayWork($interval->start, $interval->end);

            if ($index > 0) {
                $gap = SwissWorkingTimeRules::minutesBetween($today[$index - 1]->end, $interval->start);

                if ($gap >= SwissWorkingTimeRules::MINIMUM_BREAK_MINUTES) {
                    $result->breakMinutes += $gap;
                }
            }
        }

        $previous = null;

        foreach ($intervals as $interval) {
            if ($interval->end <= $today[0]->start && ($previous === null || $interval->end > $previous->end)) {
                $previous = $interval;
            }
        }

        $result->restBeforeMinutes = $previous === null ? null : SwissWorkingTimeRules::minutesBetween($previous->end, $today[0]->start);

        self::checkDetailedRules($result);

        return $result;
    }

    private static function checkDetailedRules(WorkingDayResult $result): void
    {
        $requiredBreak = SwissWorkingTimeRules::requiredBreakMinutes($result->workedMinutes);

        if ($result->breakMinutes < $requiredBreak) {
            $result->issues[] = "Pausa insufficiente: {$result->breakMinutes} min invece di {$requiredBreak} (art. 15 LL)";
        }

        if ($result->firstIn !== null && $result->lastOut !== null
            && $result->lastOut->getTimestamp() - $result->firstIn->getTimestamp() > SwissWorkingTimeRules::MAXIMUM_DAILY_SPAN_MINUTES * 60) {
            $result->issues[] = 'Spazio temporale del lavoro superiore a 14 ore (art. 10 LL)';
        }

        $rest = $result->restBeforeMinutes;

        if ($rest !== null && $rest < SwissWorkingTimeRules::REDUCED_DAILY_REST_MINUTES) {
            $result->issues[] = 'Riposo giornaliero di '.WorkingTimeReport::formatMinutes($rest).' h: inferiore a 8 ore (art. 15a LL)';
        } elseif ($rest !== null && $rest < SwissWorkingTimeRules::DAILY_REST_MINUTES) {
            $result->issues[] = 'Riposo giornaliero ridotto a '.WorkingTimeReport::formatMinutes($rest).' h: ammesso una volta a settimana con media di 11 ore su due settimane (art. 15a LL)';
        }

        self::addNightAndSundayIssues($result);
    }

    private static function addNightAndSundayIssues(WorkingDayResult $result): void
    {
        if ($result->nightMinutes > 0) {
            $result->issues[] = 'Lavoro notturno (23-06): richiede un permesso e il supplemento o compensazione previsti (art. 16-17b LL)';
        }

        if ($result->sundayWork) {
            $result->issues[] = 'Lavoro domenicale (sabato 23 - domenica 23): richiede un permesso (art. 18-19 LL)';
        }
    }

    /**
     * @param  array<int, DailyEntry>  $records
     */
    private static function evaluateSimplifiedDay(CarbonImmutable $day, array $records): WorkingDayResult
    {
        $result = new WorkingDayResult($day);
        $date = $day->format('Y-m-d');
        $record = null;

        foreach ($records as $candidate) {
            if ($candidate->workDate === $date) {
                $record = $candidate;
                break;
            }
        }

        if ($record === null) {
            return $result;
        }

        $result->workedMinutes = $record->workedMinutes;

        if ($record->startTime !== null && $record->endTime !== null && $record->endTime > $record->startTime) {
            $result->firstIn = SwissWorkingTimeRules::toLocal($record->startTime);
            $result->lastOut = SwissWorkingTimeRules::toLocal($record->endTime);
            $result->nightMinutes = SwissWorkingTimeRules::nightMinutes($result->firstIn, $result->lastOut);
            $result->sundayWork = SwissWorkingTimeRules::isSundayWork($result->firstIn, $result->lastOut);
            self::addNightAndSundayIssues($result);
        } elseif ($day->dayOfWeek === CarbonImmutable::SUNDAY && $record->workedMinutes > 0) {
            $result->sundayWork = true;
            $result->issues[] = 'Lavoro domenicale: con la registrazione semplificata vanno indicati inizio e fine (art. 73b OLL 1)';
        }

        return $result;
    }

    private static function applyTarget(WorkingDayResult $result, WorkingTimeContext $context): void
    {
        $profile = $context->profile;
        $workDays = max(1, min(7, $profile->workDaysPerWeek));
        $dayIndex = ($result->date->dayOfWeek + 6) % 7;
        $date = $result->date->format('Y-m-d');

        if ($dayIndex >= $workDays
            || ($profile->hireDate !== null && $date < $profile->hireDate)
            || ($profile->terminationDate !== null && $date > $profile->terminationDate)) {
            return;
        }

        $target = SwissWorkingTimeRules::roundedDivision(SwissWorkingTimeRules::hundredths($profile->weeklyHours) * 60, 100 * $workDays);

        foreach ($context->holidays as $holiday) {
            if ($holiday->date === $date) {
                $fraction = max(0, min(100, SwissWorkingTimeRules::hundredths($holiday->dayFraction)));
                $target = SwissWorkingTimeRules::roundedDivision($target * (100 - $fraction), 100);
                $result->absence = $holiday->name;
                break;
            }
        }

        foreach ($context->absences as $absence) {
            if ($absence->startDate <= $date && $absence->endDate >= $date) {
                $percent = max(0, min(10_000, SwissWorkingTimeRules::hundredths($absence->percent)));
                $share = SwissWorkingTimeRules::roundedDivision($target * $percent, 10_000);
                $result->absence = $absence->absenceType;

                if ($absence->isPaid) {
                    $result->creditedMinutes = $share;
                } else {
                    $target -= $share;
                }

                break;
            }
        }

        $result->targetMinutes = $target;
    }

    private static function addWeeks(WorkingTimeReport $report, float|int|string $legalMaximumHours, int $workDaysPerWeek): void
    {
        $legalMaximum = SwissWorkingTimeRules::roundedDivision(SwissWorkingTimeRules::hundredths($legalMaximumHours) * 60, 100);
        $dailyLegal = intdiv($legalMaximum, max(1, min(7, $workDaysPerWeek)));

        /** @var array<string, array<int, WorkingDayResult>> $weeks */
        $weeks = [];

        foreach ($report->days as $day) {
            $weeks[$day->date->subDays(($day->date->dayOfWeek + 6) % 7)->format('Y-m-d')][] = $day;
        }

        foreach ($weeks as $weekStart => $days) {
            $worked = array_sum(array_map(static fn (WorkingDayResult $day): int => $day->workedMinutes, $days));
            $overtime = max(0, $worked - $legalMaximum);
            $report->weeks[] = new WorkingWeekResult(SwissWorkingTimeRules::localDate($weekStart), $worked, $legalMaximum, $overtime);

            if ($overtime <= 0) {
                continue;
            }

            foreach ($days as $day) {
                if ($day->workedMinutes - $dailyLegal > SwissWorkingTimeRules::MAXIMUM_DAILY_OVERTIME_MINUTES) {
                    $day->issues[] = 'Lavoro straordinario superiore a 2 ore nel giorno (art. 12 LL)';
                }
            }
        }
    }

    private static function addPeriodIssues(WorkingTimeReport $report, EmployeeTimeProfile $profile): void
    {
        if ($report->statutoryOvertimeYearMinutes() > $report->statutoryOvertimeCapMinutes) {
            $report->issues[] = sprintf(
                'Lavoro straordinario annuo %s h oltre il limite di %d h (art. 12 cpv. 2 LL)',
                WorkingTimeReport::formatMinutes($report->statutoryOvertimeYearMinutes()),
                intdiv($report->statutoryOvertimeCapMinutes, 60),
            );
        }

        if ($profile->mode === TimeRecordingMode::Waived) {
            $report->issues[] = 'Rinuncia alla registrazione (art. 73a OLL 1): verificare CCL, accordo scritto individuale e salario annuo lordo sopra la soglia.';
        } elseif ($profile->mode === TimeRecordingMode::Simplified && $profile->agreementDate === null) {
            $report->issues[] = "Registrazione semplificata senza data dell'accordo (art. 73b OLL 1).";
        }
    }
}
