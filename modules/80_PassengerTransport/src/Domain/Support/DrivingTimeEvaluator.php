<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\Support;

use DateTimeImmutable;
use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\IssueSeverity;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;

/**
 * Simulates the driving a trip adds to a driver against the Swiss OLR 1 / ARV 1 and Regulation (EC) No 561/2006 (vehicles over
 * 3.5 t or with more than nine seats) or the OLR 2 / ARV 2 (light passenger vehicles of professional drivers: taxi and NCC).
 * Pure: counters and trip in, findings out. Minutes everywhere.
 *
 * OLR 1 / EC 561: 4 h 30 of continuous driving, then a 45 min break (or 15 + 30); 9 h of daily driving, twice a week 10 h; 56 h a
 * week; 90 h in two consecutive weeks; 11 h of daily rest, reducible to 9 h at most three times between two weekly rests; a weekly
 * rest within six 24-hour periods.
 * OLR 2: 9 h of daily driving; breaks and the 11 h daily rest as set in config('passenger_transport.olr2'); weekly rest within six days.
 */
final class DrivingTimeEvaluator
{
    private const int CONTINUOUS_LIMIT = 270;

    private const int BREAK_REQUIRED = 45;

    private const int DAILY_LIMIT = 540;

    private const int DAILY_EXTENDED_LIMIT = 600;

    private const int WEEKLY_LIMIT = 3360;

    private const int BIWEEKLY_LIMIT = 5400;

    private const int DAILY_REST_REGULAR = 660;

    private const int DAILY_REST_REDUCED = 540;

    private const int WEEKLY_REST_WINDOW_HOURS = 144;

    /**
     * @param  array{first_break_after: int, first_break_minutes: int, second_break_after: int, second_break_minutes: int, daily_limit: int, daily_rest: int}|null  $olr2
     */
    public function __construct(private readonly ?array $olr2 = null) {}

    /**
     * @return list<ComplianceIssue>
     */
    public function evaluate(DrivingRegime $regime, DriverCounters $counters, TripPlan $trip): array
    {
        return $regime === DrivingRegime::Olr1Ec561
            ? $this->evaluateOlr1($counters, $trip)
            : $this->evaluateOlr2($counters, $trip);
    }

    /**
     * Minutes of driving the driver can still add today before the next mandatory break or limit.
     */
    public function remainingMinutes(DrivingRegime $regime, DriverCounters $counters): int
    {
        $dailyLimit = $regime === DrivingRegime::Olr1Ec561
            ? ($counters->extended10hDaysUsedThisWeek < 2 ? self::DAILY_EXTENDED_LIMIT : self::DAILY_LIMIT)
            : $this->olr2Setting('daily_limit');
        $remaining = $dailyLimit - $counters->dailyDrivingMinutesUsed;

        if ($regime === DrivingRegime::Olr1Ec561) {
            $remaining = min($remaining, self::WEEKLY_LIMIT - $counters->weeklyDrivingMinutesUsed, self::BIWEEKLY_LIMIT - $counters->biweeklyDrivingMinutesUsed);
        }

        return max(0, $remaining);
    }

    /**
     * When the next break or rest is due given the continuous driving since the last break.
     */
    public function nextMandatoryBreakAt(DrivingRegime $regime, DriverCounters $counters, DateTimeImmutable $from): DateTimeImmutable
    {
        $limit = $regime === DrivingRegime::Olr1Ec561 ? self::CONTINUOUS_LIMIT : $this->olr2Setting('second_break_after');

        return $from->modify('+'.max(0, $limit - $counters->continuousDrivingMinutesSinceBreak).' minutes');
    }

    /**
     * @return list<ComplianceIssue>
     */
    private function evaluateOlr1(DriverCounters $counters, TripPlan $trip): array
    {
        $issues = [];
        $citation = 'Art. 6-8 Reg. (EC) 561/2006 / OLR 1 (ARV 1)';
        $continuous = $counters->continuousDrivingMinutesSinceBreak + $trip->drivingMinutes;

        if ($continuous > self::CONTINUOUS_LIMIT) {
            $breaksNeeded = intdiv($continuous - 1, self::CONTINUOUS_LIMIT);

            if ($trip->plannedBreakMinutes < self::BREAK_REQUIRED * $breaksNeeded) {
                $issues[] = new ComplianceIssue('continuous_driving_exceeded', IssueSeverity::Blocking, 'Art. 7 Reg. (EC) 561/2006 / Art. 7 OLR 1', ['continuous_minutes' => $continuous, 'breaks_needed' => $breaksNeeded, 'break_minutes_planned' => $trip->plannedBreakMinutes]);
            }
        } elseif ($continuous > self::CONTINUOUS_LIMIT - 30) {
            $issues[] = new ComplianceIssue('continuous_driving_close', IssueSeverity::Warning, 'Art. 7 Reg. (EC) 561/2006 / Art. 7 OLR 1', ['continuous_minutes' => $continuous]);
        }

        $daily = $counters->dailyDrivingMinutesUsed + $trip->drivingMinutes;

        if ($daily > self::DAILY_EXTENDED_LIMIT) {
            $issues[] = new ComplianceIssue('daily_driving_exceeded', IssueSeverity::Blocking, 'Art. 6(1) Reg. (EC) 561/2006 / Art. 6 OLR 1', ['daily_minutes' => $daily, 'limit_minutes' => self::DAILY_EXTENDED_LIMIT]);
        } elseif ($daily > self::DAILY_LIMIT) {
            $issues[] = $counters->extended10hDaysUsedThisWeek >= 2
                ? new ComplianceIssue('daily_driving_extension_exhausted', IssueSeverity::Blocking, 'Art. 6(1) Reg. (EC) 561/2006 / Art. 6 OLR 1', ['daily_minutes' => $daily, 'extended_days_used' => $counters->extended10hDaysUsedThisWeek])
                : new ComplianceIssue('daily_driving_extension_used', IssueSeverity::Warning, 'Art. 6(1) Reg. (EC) 561/2006 / Art. 6 OLR 1', ['daily_minutes' => $daily, 'extended_days_used' => $counters->extended10hDaysUsedThisWeek + 1]);
        }

        $weekly = $counters->weeklyDrivingMinutesUsed + $trip->drivingMinutes;

        if ($weekly > self::WEEKLY_LIMIT) {
            $issues[] = new ComplianceIssue('weekly_driving_exceeded', IssueSeverity::Blocking, 'Art. 6(2) Reg. (EC) 561/2006 / Art. 6 OLR 1', ['weekly_minutes' => $weekly, 'limit_minutes' => self::WEEKLY_LIMIT]);
        } elseif ($weekly > (int) (self::WEEKLY_LIMIT * 0.9)) {
            $issues[] = new ComplianceIssue('weekly_driving_close', IssueSeverity::Warning, 'Art. 6(2) Reg. (EC) 561/2006 / Art. 6 OLR 1', ['weekly_minutes' => $weekly]);
        }

        $biweekly = $counters->biweeklyDrivingMinutesUsed + $trip->drivingMinutes;

        if ($biweekly > self::BIWEEKLY_LIMIT) {
            $issues[] = new ComplianceIssue('biweekly_driving_exceeded', IssueSeverity::Blocking, 'Art. 6(3) Reg. (EC) 561/2006 / Art. 6 OLR 1', ['biweekly_minutes' => $biweekly, 'limit_minutes' => self::BIWEEKLY_LIMIT]);
        }

        if ($counters->lastDailyRestDurationMinutes < self::DAILY_REST_REDUCED) {
            $issues[] = new ComplianceIssue('daily_rest_insufficient', IssueSeverity::Blocking, 'Art. 8(2) Reg. (EC) 561/2006 / Art. 8 OLR 1', ['rest_minutes' => $counters->lastDailyRestDurationMinutes, 'minimum_minutes' => self::DAILY_REST_REDUCED]);
        } elseif ($counters->lastDailyRestDurationMinutes < self::DAILY_REST_REGULAR) {
            $issues[] = $counters->reduced9hRestsUsedBetweenWeeklyRests >= 3
                ? new ComplianceIssue('reduced_rests_exhausted', IssueSeverity::Blocking, 'Art. 8(4) Reg. (EC) 561/2006 / Art. 8 OLR 1', ['reduced_rests_used' => $counters->reduced9hRestsUsedBetweenWeeklyRests])
                : new ComplianceIssue('reduced_rest_used', IssueSeverity::Warning, 'Art. 8(4) Reg. (EC) 561/2006 / Art. 8 OLR 1', ['reduced_rests_used' => $counters->reduced9hRestsUsedBetweenWeeklyRests + 1]);
        }

        return [...$issues, ...$this->weeklyRestIssues($counters, $trip, $citation)];
    }

    /**
     * @return list<ComplianceIssue>
     */
    private function evaluateOlr2(DriverCounters $counters, TripPlan $trip): array
    {
        $issues = [];
        $citation = 'OLR 2 (ARV 2)';
        $continuous = $counters->continuousDrivingMinutesSinceBreak + $trip->drivingMinutes;
        $required = 0;

        if ($continuous > $this->olr2Setting('second_break_after')) {
            $required = $this->olr2Setting('second_break_minutes');
        } elseif ($continuous > $this->olr2Setting('first_break_after')) {
            $required = $this->olr2Setting('first_break_minutes');
        }

        if ($required > $trip->plannedBreakMinutes) {
            $issues[] = new ComplianceIssue('olr2_break_required', IssueSeverity::Blocking, 'Art. 8 OLR 2 (ARV 2)', ['continuous_minutes' => $continuous, 'break_minutes_required' => $required, 'break_minutes_planned' => $trip->plannedBreakMinutes]);
        }

        $daily = $counters->dailyDrivingMinutesUsed + $trip->drivingMinutes;

        if ($daily > $this->olr2Setting('daily_limit')) {
            $issues[] = new ComplianceIssue('daily_driving_exceeded', IssueSeverity::Blocking, 'Art. 6 OLR 2 (ARV 2)', ['daily_minutes' => $daily, 'limit_minutes' => $this->olr2Setting('daily_limit')]);
        }

        if ($counters->lastDailyRestDurationMinutes < $this->olr2Setting('daily_rest')) {
            $issues[] = new ComplianceIssue('daily_rest_insufficient', IssueSeverity::Blocking, 'Art. 8 OLR 2 (ARV 2)', ['rest_minutes' => $counters->lastDailyRestDurationMinutes, 'minimum_minutes' => $this->olr2Setting('daily_rest')]);
        }

        return [...$issues, ...$this->weeklyRestIssues($counters, $trip, $citation)];
    }

    /**
     * @return list<ComplianceIssue>
     */
    private function weeklyRestIssues(DriverCounters $counters, TripPlan $trip, string $citation): array
    {
        if ($counters->lastWeeklyRestEndedAt === null) {
            return [];
        }

        $hours = (int) floor(($trip->endsAt->getTimestamp() - $counters->lastWeeklyRestEndedAt->getTimestamp()) / 3600);

        return $hours > self::WEEKLY_REST_WINDOW_HOURS
            ? [new ComplianceIssue('weekly_rest_overdue', IssueSeverity::Blocking, 'Art. 8(6) Reg. (EC) 561/2006 / '.$citation, ['hours_since_weekly_rest' => $hours])]
            : [];
    }

    private function olr2Setting(string $key): int
    {
        $defaults = ['first_break_after' => 120, 'first_break_minutes' => 15, 'second_break_after' => 210, 'second_break_minutes' => 30, 'daily_limit' => 540, 'daily_rest' => 660];

        return (int) (($this->olr2 ?? [])[$key] ?? $defaults[$key]);
    }
}
