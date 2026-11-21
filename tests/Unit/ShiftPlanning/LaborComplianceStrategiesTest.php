<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Domain\Enums\IssueSeverity;
use Modules\ShiftPlanning\Domain\Services\ChLaborComplianceStrategy;
use Modules\ShiftPlanning\Domain\Services\ItLaborComplianceStrategy;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceContext;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;

/**
 * 2026-03-02 is a Monday; the week has no public holiday in Switzerland or Italy.
 */
function spPlanned(string $date, string $from, string $to, string $timezone, int $break = 0, bool $onCall = false): PlannedShift
{
    $start = CarbonImmutable::parse("{$date} {$from}", $timezone);
    $end = CarbonImmutable::parse("{$date} {$to}", $timezone);

    return new PlannedShift(null, 'employee-1', $start->utc(), ($end <= $start ? $end->addDay() : $end)->utc(), $break, $onCall);
}

/**
 * @param  list<ComplianceIssue>  $issues
 * @return list<string>
 */
function spCodes(array $issues): array
{
    return array_values(array_map(static fn (ComplianceIssue $issue): string => $issue->ruleCode, $issues));
}

/**
 * A shift of 08:00-16:00 on every date.
 *
 * @param  list<string>  $dates
 * @return list<PlannedShift>
 */
function spDayShifts(array $dates, string $timezone): array
{
    return array_map(static fn (string $date): PlannedShift => spPlanned($date, '08:00', '16:00', $timezone), $dates);
}

describe('Italy (D.Lgs. 66/2003)', function (): void {
    beforeEach(function (): void {
        $this->it = new ItLaborComplianceStrategy;
        $this->context = new ComplianceContext;
    });

    it('blocks a morning shift that follows an afternoon shift with only 8 hours of rest', function (): void {
        $afternoon = spPlanned('2026-03-02', '14:00', '22:00', 'Europe/Rome');
        $morning = spPlanned('2026-03-03', '06:00', '14:00', 'Europe/Rome');

        $issues = $this->it->validate($morning, [$afternoon], $this->context);

        expect(spCodes($issues))->toContain('IT.DAILY_REST_11H')
            ->and($issues[0]->severity)->toBe(IssueSeverity::BlockingError)
            ->and($issues[0]->params)->toMatchArray(['rest_minutes' => 480, 'required_minutes' => 660, 'side' => 'before']);
    });

    it('blocks an afternoon shift placed before a morning shift too (the rest after the candidate)', function (): void {
        $morning = spPlanned('2026-03-03', '06:00', '14:00', 'Europe/Rome');
        $afternoon = spPlanned('2026-03-02', '14:00', '22:00', 'Europe/Rome');

        expect(spCodes($this->it->validate($afternoon, [$morning], $this->context)))->toContain('IT.DAILY_REST_11H');
    });

    it('accepts exactly 11 hours of rest', function (): void {
        $afternoon = spPlanned('2026-03-02', '14:00', '22:00', 'Europe/Rome');
        $morning = spPlanned('2026-03-03', '09:00', '17:00', 'Europe/Rome');

        expect(spCodes($this->it->validate($morning, [$afternoon], $this->context)))->not->toContain('IT.DAILY_REST_11H');
    });

    it('blocks a seventh working day in the week: no weekly rest of 24 hours', function (): void {
        $others = spDayShifts(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07'], 'Europe/Rome');
        $sunday = spPlanned('2026-03-08', '08:00', '16:00', 'Europe/Rome');

        $issues = $this->it->validate($sunday, $others, $this->context);

        expect(spCodes($issues))->toContain('IT.WEEKLY_REST_24H');
    });

    it('accepts six working days with the Sunday free', function (): void {
        $others = spDayShifts(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06'], 'Europe/Rome');
        $saturday = spPlanned('2026-03-07', '08:00', '16:00', 'Europe/Rome');

        expect(spCodes($this->it->validate($saturday, $others, $this->context)))->toBe([]);
    });

    it('warns when a day is free but the longest rest of the week stays under 35 hours', function (): void {
        $others = [
            spPlanned('2026-03-02', '08:00', '16:00', 'Europe/Rome'),
            spPlanned('2026-03-03', '14:00', '23:59', 'Europe/Rome'),
            spPlanned('2026-03-05', '00:00', '08:00', 'Europe/Rome'),
            spPlanned('2026-03-06', '08:00', '16:00', 'Europe/Rome'),
            spPlanned('2026-03-07', '08:00', '16:00', 'Europe/Rome'),
        ];
        $sunday = spPlanned('2026-03-08', '08:00', '16:00', 'Europe/Rome');

        $issues = $this->it->validate($sunday, $others, $this->context);

        expect(spCodes($issues))->toBe(['IT.WEEKLY_REST_35H'])
            ->and($issues[0]->severity)->toBe(IssueSeverity::Warning);
    });

    it('warns about a night worker above 8 hours in the 24 hours', function (): void {
        $night = spPlanned('2026-03-02', '20:00', '06:00', 'Europe/Rome');

        expect(spCodes($this->it->validate($night, [], $this->context)))->toBe(['IT.NIGHT_WORK_MAX_8H']);
    });

    it('tags night, Sunday and holiday minutes and applies the CCNL percentages', function (): void {
        config(['shift_planning.italy' => ['night_surcharge_percent' => 25.0, 'sunday_surcharge_percent' => 30.0, 'holiday_surcharge_percent' => 50.0]]);

        $night = $this->it->calculateSurcharges(spPlanned('2026-03-02', '22:00', '06:00', 'Europe/Rome', 30), $this->context);
        $sunday = $this->it->calculateSurcharges(spPlanned('2026-03-08', '08:00', '16:00', 'Europe/Rome'), $this->context);
        $holiday = $this->it->calculateSurcharges(spPlanned('2026-04-25', '08:00', '16:00', 'Europe/Rome'), $this->context);
        $easterMonday = $this->it->calculateSurcharges(spPlanned('2026-04-06', '08:00', '16:00', 'Europe/Rome'), $this->context);

        expect($night->nightMinutes)->toBe(450)
            ->and($night->workedMinutes)->toBe(450)
            ->and($night->nightWageSurchargePercent)->toBe(25.0)
            ->and($night->tags())->toBe(['night'])
            ->and($sunday->sundayMinutes)->toBe(480)
            ->and($sunday->sundayWageSurchargePercent)->toBe(30.0)
            ->and($sunday->tags())->toBe(['sunday'])
            ->and($holiday->holidayMinutes)->toBe(480)
            ->and($holiday->tags())->toBe(['holiday'])
            ->and($easterMonday->holidayMinutes)->toBe(480);
    });

    it('counts a shift across midnight into the Sunday only for the minutes of the Sunday', function (): void {
        $saturdayNight = $this->it->calculateSurcharges(spPlanned('2026-03-07', '20:00', '04:00', 'Europe/Rome'), $this->context);

        expect($saturdayNight->sundayMinutes)->toBe(240)
            ->and($saturdayNight->nightMinutes)->toBe(360)
            ->and($saturdayNight->tags())->toBe(['night', 'sunday']);
    });

    it('tags on-call minutes', function (): void {
        $onCall = $this->it->calculateSurcharges(spPlanned('2026-03-02', '08:00', '16:00', 'Europe/Rome', 0, true), $this->context);

        expect($onCall->onCallMinutes)->toBe(480)->and($onCall->tags())->toBe(['on_call']);
    });
});

describe('Switzerland (ArG / LL)', function (): void {
    beforeEach(function (): void {
        $this->ch = new ChLaborComplianceStrategy;
        $this->context = new ComplianceContext;
    });

    it('allows six consecutive working days and blocks the seventh (Art. 21 LL)', function (): void {
        $six = spDayShifts(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06'], 'Europe/Zurich');
        $sixth = spPlanned('2026-03-07', '08:00', '16:00', 'Europe/Zurich');
        $seventh = spPlanned('2026-03-08', '08:00', '16:00', 'Europe/Zurich');

        expect(spCodes($this->ch->validate($sixth, $six, $this->context)))->not->toContain('CH.MAX_CONSECUTIVE_DAYS');

        $issues = $this->ch->validate($seventh, [...$six, $sixth], $this->context);

        expect(spCodes($issues))->toContain('CH.MAX_CONSECUTIVE_DAYS')
            ->and($issues[0]->params)->toMatchArray(['consecutive_days' => 7, 'maximum_days' => 6]);
    });

    it('blocks a rest under 8 hours and warns on a first reduction to 8 hours (Art. 15a ArGV 1)', function (): void {
        $afternoon = spPlanned('2026-03-02', '14:00', '22:00', 'Europe/Zurich');

        $eightHours = $this->ch->validate(spPlanned('2026-03-03', '06:00', '14:00', 'Europe/Zurich'), [$afternoon], $this->context);
        $sevenHours = $this->ch->validate(spPlanned('2026-03-03', '05:00', '13:00', 'Europe/Zurich'), [$afternoon], $this->context);

        expect(spCodes($eightHours))->toBe(['CH.DAILY_REST_REDUCED'])
            ->and($eightHours[0]->severity)->toBe(IssueSeverity::Warning)
            ->and(spCodes($sevenHours))->toBe(['CH.DAILY_REST_11H'])
            ->and($sevenHours[0]->severity)->toBe(IssueSeverity::BlockingError);
    });

    it('blocks a second reduced rest in the same week', function (): void {
        $others = [
            spPlanned('2026-03-02', '14:00', '22:00', 'Europe/Zurich'),
            spPlanned('2026-03-03', '06:00', '14:00', 'Europe/Zurich'),
            spPlanned('2026-03-04', '14:00', '22:00', 'Europe/Zurich'),
        ];

        $issues = $this->ch->validate(spPlanned('2026-03-05', '06:00', '14:00', 'Europe/Zurich'), $others, $this->context);

        expect(spCodes($issues))->toBe(['CH.DAILY_REST_11H'])->and($issues[0]->isBlocking())->toBeTrue();
    });

    it('grants a 25% wage supplement for temporary night work and 10% time compensation for regular night work (Art. 17b ArG)', function (): void {
        $night = spPlanned('2026-03-02', '22:00', '06:00', 'Europe/Zurich');

        $temporary = $this->ch->calculateSurcharges($night, new ComplianceContext(regularNightWork: false));
        $regular = $this->ch->calculateSurcharges($night, new ComplianceContext(regularNightWork: true));

        expect($temporary->nightMinutes)->toBe(420)
            ->and($temporary->nightWageSurchargePercent)->toBe(25.0)
            ->and($temporary->nightTimeCompensationMinutes)->toBe(0)
            ->and($regular->nightWageSurchargePercent)->toBeNull()
            ->and($regular->nightTimeCompensationMinutes)->toBe(42);
    });

    it('does not surcharge night minutes of a template without night surcharge', function (): void {
        $night = spPlanned('2026-03-02', '22:00', '06:00', 'Europe/Zurich');
        $shift = new PlannedShift(null, 'employee-1', $night->startAt, $night->endAt, 0, false, false);

        expect($this->ch->calculateSurcharges($shift, $this->context)->nightMinutes)->toBe(0);
    });

    it('warns that Sunday work needs a permit and a compensatory rest in the week before or after', function (): void {
        $sunday = spPlanned('2026-03-08', '08:00', '16:00', 'Europe/Zurich');

        expect(spCodes($this->ch->validate($sunday, [], $this->context)))->toBe(['CH.SUNDAY_WORK_PERMIT']);

        $around = spDayShifts(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07', '2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13', '2026-03-14'], 'Europe/Zurich');

        expect(spCodes($this->ch->validate($sunday, $around, $this->context)))->toContain('CH.SUNDAY_COMPENSATORY_REST');
    });

    it('surcharges Sunday and holiday work with 50%', function (): void {
        $sunday = $this->ch->calculateSurcharges(spPlanned('2026-03-08', '08:00', '16:00', 'Europe/Zurich'), $this->context);
        $nationalDay = $this->ch->calculateSurcharges(spPlanned('2026-08-01', '08:00', '16:00', 'Europe/Zurich'), $this->context);

        expect($sunday->sundayWageSurchargePercent)->toBe(50.0)
            ->and($nationalDay->holidayMinutes)->toBe(480)
            ->and($nationalDay->holidayWageSurchargePercent)->toBe(50.0);
    });

    it('measures a night shift across the daylight-saving change in real time', function (): void {
        // 2026-03-29: clocks go forward at 02:00, the night 22:00-06:00 lasts 7 real hours.
        $shift = spPlanned('2026-03-28', '22:00', '06:00', 'Europe/Zurich');

        expect($shift->workedMinutes())->toBe(420)
            ->and($this->ch->calculateSurcharges($shift, $this->context)->nightMinutes)->toBe(360);
    });
});
