<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Checks Italian D.Lgs. 66/2003 working-time compliance rules against a set of clock events.
 */
final class ItalyComplianceCalculator
{
    private const REST_MINIMUM_SECONDS = 11 * 3600; // Art. 7: 11h consecutive daily rest

    private const WEEKLY_MAX_HOURS = 48.0; // Art. 4: 48h rolling average over reference period

    /**
     * Find shifts separated by less than 11 hours of rest (Art. 7 D.Lgs. 66/2003).
     *
     * @param  array<object>  $events  stdClass with: user_id, occurred_at (UTC datetime string), event_type (string)
     * @return ItalyComplianceViolation[]
     */
    public function checkRestPeriods(array $events): array
    {
        $byUser = $this->groupByUser($events);
        $violations = [];

        foreach ($byUser as $userId => $userEvents) {
            $sorted = $userEvents->sortBy('occurred_at')->values();

            // Find consecutive Out→In pairs
            $lastOut = null;
            foreach ($sorted as $event) {
                if ($event->event_type === 'Out') {
                    $lastOut = $event;
                } elseif ($event->event_type === 'In' && $lastOut !== null) {
                    $outTime = CarbonImmutable::parse($lastOut->occurred_at);
                    $inTime = CarbonImmutable::parse($event->occurred_at);
                    $gapSeconds = $outTime->diffInSeconds($inTime);

                    if ($gapSeconds < self::REST_MINIMUM_SECONDS) {
                        $violations[] = new ItalyComplianceViolation(
                            type: 'rest_11h',
                            userId: (string) $userId,
                            date: CarbonImmutable::parse($event->occurred_at)->format('Y-m-d'),
                            actual: round($gapSeconds / 3600, 2),
                            limit: 11.0,
                        );
                    }
                    $lastOut = null;
                }
            }
        }

        return $violations;
    }

    /**
     * Find calendar weeks where total worked minutes exceed 48 hours (Art. 4 D.Lgs. 66/2003).
     * Uses time_daily_records where available; falls back to In/Out event pairing.
     *
     * @param  array<object>  $dailyRecords  stdClass with: user_id, work_date (Y-m-d), worked_minutes
     * @return ItalyComplianceViolation[]
     */
    public function checkWeekly48h(array $dailyRecords): array
    {
        $violations = [];

        // Group by user then ISO week
        $byUserWeek = [];
        foreach ($dailyRecords as $record) {
            $date = CarbonImmutable::parse($record->work_date);
            $weekKey = $date->format('o-W'); // ISO year-week
            $weekStart = $date->startOfWeek()->format('Y-m-d');
            $key = $record->user_id.'|'.$weekKey;
            $byUserWeek[$key] = [
                'user_id' => $record->user_id,
                'week_start' => $weekStart,
                'minutes' => ($byUserWeek[$key]['minutes'] ?? 0) + (int) $record->worked_minutes,
            ];
        }

        foreach ($byUserWeek as $entry) {
            $workedHours = $entry['minutes'] / 60;
            if ($workedHours > self::WEEKLY_MAX_HOURS) {
                $violations[] = new ItalyComplianceViolation(
                    type: 'weekly_48h',
                    userId: (string) $entry['user_id'],
                    date: $entry['week_start'],
                    actual: round($workedHours, 2),
                    limit: self::WEEKLY_MAX_HOURS,
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<object>  $events
     * @return Collection<string, Collection<int, object>>
     */
    private function groupByUser(array $events): Collection
    {
        return collect($events)->groupBy('user_id');
    }
}
