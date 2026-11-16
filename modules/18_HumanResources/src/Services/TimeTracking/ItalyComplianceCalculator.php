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
     * Check whether the rolling average weekly hours over the reference period exceeds 48 h (Art. 4 D.Lgs. 66/2003).
     *
     * The law requires the AVERAGE over a reference period of up to 4 months (Art. 4 cpv. 3) not to exceed 48 h.
     * A single week above 48 h is therefore not a violation provided the period average stays below.
     *
     * @param  array<object>  $dailyRecords  stdClass with: user_id, work_date (Y-m-d), worked_minutes
     * @return ItalyComplianceViolation[]
     */
    public function checkWeekly48h(array $dailyRecords, int $referencePeriodMonths = 4): array
    {
        $violations = [];

        $byUser = [];
        foreach ($dailyRecords as $record) {
            $userId = (string) $record->user_id;
            $date = CarbonImmutable::parse($record->work_date);
            $minutes = (int) $record->worked_minutes;

            if (! isset($byUser[$userId])) {
                $byUser[$userId] = ['user_id' => $userId, 'total_minutes' => 0, 'min_date' => $date, 'max_date' => $date];
            } else {
                if ($date < $byUser[$userId]['min_date']) {
                    $byUser[$userId]['min_date'] = $date;
                }
                if ($date > $byUser[$userId]['max_date']) {
                    $byUser[$userId]['max_date'] = $date;
                }
            }

            $byUser[$userId]['total_minutes'] += $minutes;
        }

        foreach ($byUser as $entry) {
            /** @var CarbonImmutable $minDate */
            $minDate = $entry['min_date'];
            /** @var CarbonImmutable $maxDate */
            $maxDate = $entry['max_date'];

            $days = max(1, $minDate->diffInDays($maxDate) + 1);
            $weeks = $days / 7.0;
            $avgHoursPerWeek = ($entry['total_minutes'] / 60.0) / max(1.0, $weeks);

            if ($avgHoursPerWeek > self::WEEKLY_MAX_HOURS) {
                $violations[] = new ItalyComplianceViolation(
                    type: 'weekly_48h_avg',
                    userId: $entry['user_id'],
                    date: $minDate->format('Y-m-d'),
                    actual: round($avgHoursPerWeek, 2),
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
