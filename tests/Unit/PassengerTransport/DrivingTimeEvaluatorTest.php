<?php

declare(strict_types=1);

use Modules\PassengerTransport\Domain\Enums\DrivingRegime;
use Modules\PassengerTransport\Domain\Enums\IssueSeverity;
use Modules\PassengerTransport\Domain\Support\DrivingTimeEvaluator;
use Modules\PassengerTransport\Domain\ValueObjects\ComplianceIssue;
use Modules\PassengerTransport\Domain\ValueObjects\DriverCounters;
use Modules\PassengerTransport\Domain\ValueObjects\TripPlan;

/**
 * @param  list<ComplianceIssue>  $issues
 * @return list<string>
 */
function ptrCodes(array $issues, ?IssueSeverity $severity = null): array
{
    return array_values(array_map(
        static fn (ComplianceIssue $issue): string => $issue->code,
        array_filter($issues, static fn (ComplianceIssue $issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

function ptrTrip(int $drivingMinutes, int $breakMinutes = 0, string $start = '2028-03-10 08:00:00'): TripPlan
{
    $startsAt = new DateTimeImmutable($start);

    return new TripPlan($startsAt, $startsAt->modify('+'.($drivingMinutes + $breakMinutes).' minutes'), $drivingMinutes, $breakMinutes);
}

it('accepts a short trip of a rested driver under OLR 1 / EC 561', function (): void {
    $issues = (new DrivingTimeEvaluator)->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(120));

    expect($issues)->toBe([]);
});

it('blocks more than 4 h 30 of continuous driving without a 45 minute break', function (): void {
    $evaluator = new DrivingTimeEvaluator;

    $blocked = $evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(300, 20));
    $allowed = $evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(300, 45));

    expect(ptrCodes($blocked, IssueSeverity::Blocking))->toBe(['continuous_driving_exceeded'])
        ->and(ptrCodes($allowed, IssueSeverity::Blocking))->toBe([]);
});

it('counts the continuous driving already done since the last break', function (): void {
    $issues = (new DrivingTimeEvaluator)->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(continuousDrivingMinutesSinceBreak: 200), ptrTrip(100));

    expect(ptrCodes($issues, IssueSeverity::Blocking))->toBe(['continuous_driving_exceeded']);
});

it('needs a second break when the driving passes 9 hours of continuous time', function (): void {
    $evaluator = new DrivingTimeEvaluator;

    expect(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(560, 45)), IssueSeverity::Blocking))->toContain('continuous_driving_exceeded')
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(560, 90)), IssueSeverity::Blocking))->not->toContain('continuous_driving_exceeded');
});

it('allows 9 hours a day, 10 hours twice a week, and blocks above', function (): void {
    $evaluator = new DrivingTimeEvaluator;
    $break = 90;

    expect(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(540, $break))))->toBe([])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(580, $break))))->toBe(['daily_driving_extension_used'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(extended10hDaysUsedThisWeek: 2), ptrTrip(580, $break)), IssueSeverity::Blocking))->toBe(['daily_driving_extension_exhausted'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(610, $break)), IssueSeverity::Blocking))->toBe(['daily_driving_exceeded']);
});

it('blocks above 56 hours a week and 90 hours in two weeks and warns near the weekly limit', function (): void {
    $evaluator = new DrivingTimeEvaluator;

    expect(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(weeklyDrivingMinutesUsed: 3300), ptrTrip(120)), IssueSeverity::Blocking))->toBe(['weekly_driving_exceeded'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(weeklyDrivingMinutesUsed: 3000), ptrTrip(60)), IssueSeverity::Warning))->toBe(['weekly_driving_close'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(weeklyDrivingMinutesUsed: 2000, biweeklyDrivingMinutesUsed: 5350), ptrTrip(120)), IssueSeverity::Blocking))->toBe(['biweekly_driving_exceeded']);
});

it('limits the daily rest: 11 h regular, 9 h reduced at most three times, never below 9 h', function (): void {
    $evaluator = new DrivingTimeEvaluator;

    expect(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(lastDailyRestDurationMinutes: 660), ptrTrip(60))))->toBe([])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(lastDailyRestDurationMinutes: 560, reduced9hRestsUsedBetweenWeeklyRests: 1), ptrTrip(60)), IssueSeverity::Warning))->toBe(['reduced_rest_used'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(lastDailyRestDurationMinutes: 560, reduced9hRestsUsedBetweenWeeklyRests: 3), ptrTrip(60)), IssueSeverity::Blocking))->toBe(['reduced_rests_exhausted'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters(lastDailyRestDurationMinutes: 500), ptrTrip(60)), IssueSeverity::Blocking))->toBe(['daily_rest_insufficient']);
});

it('blocks a trip that ends more than six 24-hour periods after the last weekly rest', function (): void {
    $evaluator = new DrivingTimeEvaluator;
    $recent = new DriverCounters(lastWeeklyRestEndedAt: new DateTimeImmutable('2028-03-06 08:00:00'));
    $old = new DriverCounters(lastWeeklyRestEndedAt: new DateTimeImmutable('2028-03-01 08:00:00'));

    expect($evaluator->evaluate(DrivingRegime::Olr1Ec561, $recent, ptrTrip(120)))->toBe([])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr1Ec561, $old, ptrTrip(120)), IssueSeverity::Blocking))->toBe(['weekly_rest_overdue']);
});

it('applies the OLR 2 breaks, 9 hour daily limit and 11 hour rest to light vehicles', function (): void {
    $evaluator = new DrivingTimeEvaluator;

    expect(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters, ptrTrip(100))))->toBe([])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters, ptrTrip(150)), IssueSeverity::Blocking))->toBe(['olr2_break_required'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters, ptrTrip(150, 15)), IssueSeverity::Blocking))->toBe([])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters, ptrTrip(230, 15)), IssueSeverity::Blocking))->toBe(['olr2_break_required'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters(dailyDrivingMinutesUsed: 500), ptrTrip(60, 30)), IssueSeverity::Blocking))->toBe(['daily_driving_exceeded'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters(lastDailyRestDurationMinutes: 600), ptrTrip(60)), IssueSeverity::Blocking))->toBe(['daily_rest_insufficient']);
});

it('reads the OLR 2 thresholds from the configuration it is given', function (): void {
    $evaluator = new DrivingTimeEvaluator(['first_break_after' => 60, 'first_break_minutes' => 10, 'second_break_after' => 150, 'second_break_minutes' => 20, 'daily_limit' => 480, 'daily_rest' => 600]);

    expect(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters, ptrTrip(90)), IssueSeverity::Blocking))->toBe(['olr2_break_required'])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters, ptrTrip(90, 10)), IssueSeverity::Blocking))->toBe([])
        ->and(ptrCodes($evaluator->evaluate(DrivingRegime::Olr2, new DriverCounters(dailyDrivingMinutesUsed: 450), ptrTrip(60, 20)), IssueSeverity::Blocking))->toBe(['daily_driving_exceeded']);
});

it('computes the driving time left today and the next mandatory break', function (): void {
    $evaluator = new DrivingTimeEvaluator;
    $counters = new DriverCounters(dailyDrivingMinutesUsed: 400, continuousDrivingMinutesSinceBreak: 100);

    expect($evaluator->remainingMinutes(DrivingRegime::Olr1Ec561, $counters))->toBe(200)
        ->and($evaluator->remainingMinutes(DrivingRegime::Olr1Ec561, new DriverCounters(dailyDrivingMinutesUsed: 400, extended10hDaysUsedThisWeek: 2)))->toBe(140)
        ->and($evaluator->remainingMinutes(DrivingRegime::Olr1Ec561, new DriverCounters(dailyDrivingMinutesUsed: 100, weeklyDrivingMinutesUsed: 3300)))->toBe(60)
        ->and($evaluator->remainingMinutes(DrivingRegime::Olr2, new DriverCounters(dailyDrivingMinutesUsed: 600)))->toBe(0)
        ->and($evaluator->nextMandatoryBreakAt(DrivingRegime::Olr1Ec561, $counters, new DateTimeImmutable('2028-03-10 08:00:00'))->format('H:i'))->toBe('10:50');
});

it('turns a finding into an API payload with its legal citation', function (): void {
    $issue = (new DrivingTimeEvaluator)->evaluate(DrivingRegime::Olr1Ec561, new DriverCounters, ptrTrip(610, 90))[0];

    expect($issue->isBlocking())->toBeTrue()
        ->and($issue->citation)->toContain('561/2006')
        ->and($issue->demoted()->severity)->toBe(IssueSeverity::Warning)
        ->and($issue->toArray())->toHaveKeys(['code', 'severity', 'citation', 'params', 'message']);
});
