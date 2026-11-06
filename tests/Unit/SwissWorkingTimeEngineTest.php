<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\HumanResources\Services\TimeTracking\AbsenceEntry;
use Modules\HumanResources\Services\TimeTracking\ClockEntry;
use Modules\HumanResources\Services\TimeTracking\DailyEntry;
use Modules\HumanResources\Services\TimeTracking\DataRetentionPolicy;
use Modules\HumanResources\Services\TimeTracking\EmployeeTimeProfile;
use Modules\HumanResources\Services\TimeTracking\HolidayEntry;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeEngine;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeEventType;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeContext;
use Modules\HumanResources\Services\TimeTracking\WorkingTimeReport;

const WTE_MONDAY = '2026-11-02';

/**
 * Alternating entries and exits on a local day.
 *
 * @return array<int, ClockEntry>
 */
function wteDay(string $date, string ...$times): array
{
    $events = [];

    foreach ($times as $index => $time) {
        $events[] = wteClock("{$date} {$time}", $index % 2 === 0 ? TimeEventType::In : TimeEventType::Out);
    }

    return $events;
}

function wteClock(string $localTime, TimeEventType $eventType): ClockEntry
{
    return new ClockEntry(CarbonImmutable::parse($localTime, SwissWorkingTimeRules::TIMEZONE), $eventType);
}

function wteDate(string $date, int $addDays = 0): CarbonImmutable
{
    return SwissWorkingTimeRules::localDate($date)->addDays($addDays);
}

/**
 * The desktop test employee: 42 hours in 5 days, legal maximum of 45 hours.
 *
 * @param  array<int, ClockEntry>  $events
 */
function wteContext(array $events, int $days = 1, string $from = WTE_MONDAY, ?EmployeeTimeProfile $profile = null): WorkingTimeContext
{
    return new WorkingTimeContext(
        profile: $profile ?? new EmployeeTimeProfile(employeeName: 'Anna Bianchi', hireDate: '2020-01-01'),
        from: wteDate($from),
        to: wteDate($from, $days - 1),
        now: CarbonImmutable::parse('2026-12-31 12:00:00', SwissWorkingTimeRules::TIMEZONE),
        events: $events,
    );
}

/**
 * @param  array<int, ClockEntry>  $events
 */
function wteEvaluate(array $events, int $days = 1): WorkingTimeReport
{
    return SwissWorkingTimeEngine::evaluate(wteContext($events, $days));
}

/**
 * @param  array<int, string>  $issues
 */
function wteHasIssue(array $issues, string $text): bool
{
    return array_filter($issues, static fn (string $issue): bool => str_contains($issue, $text)) !== [];
}

test('a regular day computes worked time, break and balance against 8h24', function (): void {
    $day = wteEvaluate(wteDay(WTE_MONDAY, '08:00', '12:00', '12:45', '17:15'))->days[0];

    expect($day->workedMinutes)->toBe(510)
        ->and($day->breakMinutes)->toBe(45)
        ->and($day->targetMinutes)->toBe(504)
        ->and($day->balanceMinutes())->toBe(6)
        ->and($day->issues)->toBe([]);
});

test('more than 9 hours of work requires a one-hour break (art. 15 LL)', function (): void {
    $day = wteEvaluate(wteDay(WTE_MONDAY, '07:00', '12:00', '12:30', '17:30'))->days[0];

    expect(wteHasIssue($day->issues, 'art. 15 LL'))->toBeTrue();
});

test('less than 11 hours of rest between two days and night work are reported', function (): void {
    $report = wteEvaluate([...wteDay(WTE_MONDAY, '14:00', '23:30'), ...wteDay('2026-11-03', '07:00', '12:00')], 2);

    expect(wteHasIssue($report->days[0]->issues, 'notturno'))->toBeTrue()
        ->and($report->days[0]->nightMinutes)->toBe(30)
        ->and($report->days[1]->restBeforeMinutes)->toBe(450)
        ->and(wteHasIssue($report->days[1]->issues, 'inferiore a 8 ore'))->toBeTrue();
});

test('a missing exit is reported, holidays and paid absences adjust the target, Sunday work is detected', function (): void {
    $context = wteContext([wteClock(WTE_MONDAY.' 08:00', TimeEventType::In), ...wteDay('2026-11-08', '09:00', '11:00')], 7);
    $context->holidays = [new HolidayEntry('2026-11-03', 'Ognissanti')];
    $context->absences = [new AbsenceEntry('Vacation', '2026-11-04', '2026-11-04', isPaid: true)];

    $report = SwissWorkingTimeEngine::evaluate($context);

    expect(wteHasIssue($report->days[0]->issues, 'senza uscita'))->toBeTrue()
        ->and($report->days[1]->targetMinutes)->toBe(0)
        ->and($report->days[2]->creditedMinutes)->toBe(504)
        ->and($report->days[2]->balanceMinutes())->toBe(0)
        ->and($report->days[6]->sundayWork)->toBeTrue()
        ->and($report->days[6]->targetMinutes)->toBe(0);
});

test('work beyond the legal maximum is statutory overtime, also against the yearly cap', function (): void {
    $events = [];

    for ($i = 0; $i < 5; $i++) {
        array_push($events, ...wteDay(wteDate(WTE_MONDAY, $i)->format('Y-m-d'), '07:00', '12:00', '13:00', '18:00'));
    }

    $context = wteContext($events, 7);
    $context->statutoryOvertimeBeforeMinutes = 169 * 60;

    $report = SwissWorkingTimeEngine::evaluate($context);

    expect($report->weeks[0]->workedMinutes)->toBe(50 * 60)
        ->and($report->statutoryOvertimeMinutes())->toBe(5 * 60)
        ->and($report->issues)->toContain('Lavoro straordinario annuo 174:00 h oltre il limite di 170 h (art. 12 cpv. 2 LL)');
});

test('pause thresholds, the 50-hour cap and Easter follow the Labour Act and the desktop', function (): void {
    expect(SwissWorkingTimeRules::requiredBreakMinutes(330))->toBe(0)
        ->and(SwissWorkingTimeRules::requiredBreakMinutes(331))->toBe(15)
        ->and(SwissWorkingTimeRules::requiredBreakMinutes(421))->toBe(30)
        ->and(SwissWorkingTimeRules::requiredBreakMinutes(541))->toBe(60)
        ->and(SwissWorkingTimeRules::yearlyOvertimeCapMinutes(50))->toBe(140 * 60)
        ->and(SwissHolidayCalendar::easterSunday(2026)->format('Y-m-d'))->toBe('2026-04-05')
        ->and(array_column(SwissHolidayCalendar::forCanton('TI', 2026), 'name'))->toContain('San Giuseppe');
});

test('the retention keeps at least five full calendar years', function (): void {
    expect(DataRetentionPolicy::cutoff(wteDate('2026-11-06'), 5)->format('Y-m-d'))->toBe('2021-01-01')
        ->and(DataRetentionPolicy::cutoff(wteDate('2026-11-06'), 2)->format('Y-m-d'))->toBe('2021-01-01')
        ->and(DataRetentionPolicy::cutoff(wteDate('2026-01-01'), 10)->format('Y-m-d'))->toBe('2016-01-01')
        ->and(DataRetentionPolicy::cutoff(wteDate('2026-01-01'), 99)->format('Y-m-d'))->toBe('1996-01-01');
});

test('the retention year is the local one on the night of New Year', function (): void {
    $newYearInZurich = CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC');

    expect(DataRetentionPolicy::cutoff($newYearInZurich, 5)->format('Y-m-d'))->toBe('2022-01-01');
});

test('the cantonal holiday lists are applied', function (string $canton, string $holiday, bool $expected): void {
    expect(in_array($holiday, array_column(SwissHolidayCalendar::forCanton($canton, 2026), 'name'), true))->toBe($expected);
})->with([
    ['ZH', 'San Bertoldo', true],
    ['ZH', 'Festa del lavoro', true],
    ['TI', 'Venerdì Santo', false],
    ['TI', 'SS. Pietro e Paolo', true],
    ['GE', 'Restaurazione della Repubblica', true],
    ['VD', 'Lunedì del Digiuno federale', true],
    ['VS', 'Lunedì di Pasqua', false],
    ['BE', 'Santo Stefano', true],
]);

test('movable cantonal holidays are computed', function (): void {
    expect(array_column(SwissHolidayCalendar::forCanton('GE', 2026), 'date'))->toContain('2026-09-10')
        ->and(array_column(SwissHolidayCalendar::forCanton('VD', 2026), 'date'))->toContain('2026-09-21')
        ->and(array_column(SwissHolidayCalendar::forCanton('GL', 2026), 'date'))->toContain('2026-04-09');
});

test('all 26 cantons have a calendar ordered by date, and an empty canton is Ticino', function (): void {
    expect(SwissHolidayCalendar::CANTONS)->toHaveCount(26);

    foreach (SwissHolidayCalendar::CANTONS as $canton) {
        $dates = array_column(SwissHolidayCalendar::forCanton($canton, 2026), 'date');
        $sorted = $dates;
        sort($sorted);

        expect($dates)->toBe($sorted)->toContain('2026-08-01');
    }

    expect(SwissHolidayCalendar::forCanton(null, 2026))->toEqual(SwissHolidayCalendar::forCanton('TI', 2026))
        ->and(SwissHolidayCalendar::forCanton(' zh ', 2026))->toEqual(SwissHolidayCalendar::forCanton('ZH', 2026));
});

test('the day of an event is the local one, not the UTC one', function (): void {
    $events = [
        new ClockEntry(CarbonImmutable::parse('2026-06-30 22:30:00', 'UTC'), TimeEventType::In),
        new ClockEntry(CarbonImmutable::parse('2026-07-01 02:30:00', 'UTC'), TimeEventType::Out),
    ];

    $report = SwissWorkingTimeEngine::evaluate(wteContext($events, 2, '2026-06-30'));

    expect($report->days[0]->workedMinutes)->toBe(0)
        ->and($report->days[1]->workedMinutes)->toBe(240)
        ->and($report->days[1]->firstIn?->format('Y-m-d H:i'))->toBe('2026-07-01 00:30')
        ->and($report->days[1]->nightMinutes)->toBe(240);
});

test('Sunday and night windows are in local time', function (): void {
    // Saturday 23:30 in Zurich is 22:30 UTC, still outside the UTC Sunday.
    $start = CarbonImmutable::parse('2026-11-07 22:30:00', 'UTC');
    $end = CarbonImmutable::parse('2026-11-07 23:30:00', 'UTC');

    expect(SwissWorkingTimeRules::isSundayWork($start, $end))->toBeTrue()
        ->and(SwissWorkingTimeRules::nightMinutes($start, $end))->toBe(60);

    // Saturday 22:00 - 22:59 local is not Sunday, Sunday 23:00 local is no longer Sunday.
    expect(SwissWorkingTimeRules::isSundayWork(wteDate('2026-11-07')->setTime(22, 0), wteDate('2026-11-07')->setTime(22, 59)))->toBeFalse()
        ->and(SwissWorkingTimeRules::isSundayWork(wteDate('2026-11-08')->setTime(23, 0), wteDate('2026-11-08')->setTime(23, 30)))->toBeFalse();
});

test('a shift across midnight belongs to the day it starts', function (): void {
    $report = wteEvaluate([wteClock(WTE_MONDAY.' 22:00', TimeEventType::In), wteClock('2026-11-03 02:00', TimeEventType::Out)], 2);

    expect($report->days[0]->workedMinutes)->toBe(240)
        ->and($report->days[0]->nightMinutes)->toBe(180)
        ->and($report->days[1]->workedMinutes)->toBe(0);
});

test('the clock change counts the time really worked', function (): void {
    $spring = SwissWorkingTimeEngine::evaluate(wteContext(wteDay('2026-03-29', '01:00', '04:00'), 1, '2026-03-29'));
    $autumn = SwissWorkingTimeEngine::evaluate(wteContext(wteDay('2026-10-25', '01:00', '04:00'), 1, '2026-10-25'));

    expect($spring->days[0]->workedMinutes)->toBe(120)
        ->and($autumn->days[0]->workedMinutes)->toBe(240);
});

test('seconds are rounded to the nearest minute, half away from zero', function (): void {
    $start = wteDate(WTE_MONDAY)->setTime(8, 0);

    expect(SwissWorkingTimeRules::minutesBetween($start, $start->addSeconds(89)))->toBe(1)
        ->and(SwissWorkingTimeRules::minutesBetween($start, $start->addSeconds(90)))->toBe(2);
});

test('the target is rounded on exact decimals like a C# decimal', function (): void {
    $midpoint = new EmployeeTimeProfile(weeklyHours: '40.25', workDaysPerWeek: 2);
    $halfDay = new EmployeeTimeProfile(weeklyHours: '42.10', workDaysPerWeek: 5);

    $context = wteContext([], 1, WTE_MONDAY, $halfDay);
    $context->holidays = [new HolidayEntry(WTE_MONDAY, 'Mezza giornata', '0.50')];

    expect(SwissWorkingTimeEngine::evaluate(wteContext([], 1, WTE_MONDAY, $midpoint))->days[0]->targetMinutes)->toBe(1208)
        ->and(SwissWorkingTimeEngine::evaluate($context)->days[0]->targetMinutes)->toBe(253);
});

test('the daily legal limit truncates like the integer division of the desktop', function (): void {
    $profile = new EmployeeTimeProfile(legalMaxWeeklyHours: 45, workDaysPerWeek: 7);
    $events = [];

    // 2700 / 7 = 385: 506 minutes a day exceed it by 121, 505 by 120, which is still allowed.
    for ($i = 0; $i < 6; $i++) {
        array_push($events, ...wteDay(wteDate(WTE_MONDAY, $i)->format('Y-m-d'), '06:00', '10:00', '11:00', '15:25'));
    }

    array_push($events, ...wteDay('2026-11-08', '06:00', '10:00', '11:00', '15:26'));

    $report = SwissWorkingTimeEngine::evaluate(wteContext($events, 7, WTE_MONDAY, $profile));

    expect(wteHasIssue($report->days[0]->issues, 'superiore a 2 ore'))->toBeFalse()
        ->and(wteHasIssue($report->days[6]->issues, 'superiore a 2 ore'))->toBeTrue();
});

test('unpaid absences reduce the target and a partial one only by its share', function (): void {
    $context = wteContext([], 2);
    $context->absences = [
        new AbsenceEntry('Unpaid', WTE_MONDAY, WTE_MONDAY, isPaid: false),
        new AbsenceEntry('Sickness', '2026-11-03', '2026-11-03', isPaid: true, percent: 50),
    ];

    $report = SwissWorkingTimeEngine::evaluate($context);

    expect($report->days[0]->targetMinutes)->toBe(0)
        ->and($report->days[0]->absence)->toBe('Unpaid')
        ->and($report->days[1]->targetMinutes)->toBe(504)
        ->and($report->days[1]->creditedMinutes)->toBe(252);
});

test('no target before the hire date, after the termination date or beyond the working days', function (): void {
    $profile = new EmployeeTimeProfile(hireDate: '2026-11-03', terminationDate: '2026-11-04');

    $days = SwissWorkingTimeEngine::evaluate(wteContext([], 7, WTE_MONDAY, $profile))->days;

    expect(array_map(static fn ($day): int => $day->targetMinutes, $days))->toBe([0, 504, 504, 0, 0, 0, 0]);
});

test('the simplified recording uses the daily duration and needs the agreement date', function (): void {
    $profile = new EmployeeTimeProfile(mode: TimeRecordingMode::Simplified);
    $context = wteContext(wteDay(WTE_MONDAY, '08:00', '12:00'), 7, WTE_MONDAY, $profile);
    $context->dailyRecords = [
        new DailyEntry(WTE_MONDAY, 480),
        new DailyEntry('2026-11-07', 60, wteDate('2026-11-07')->setTime(23, 0), wteDate('2026-11-08')->setTime(0, 0)),
        new DailyEntry('2026-11-08', 120),
    ];

    $report = SwissWorkingTimeEngine::evaluate($context);

    expect($report->days[0]->workedMinutes)->toBe(480)
        ->and($report->days[0]->breakMinutes)->toBe(0)
        ->and($report->days[5]->nightMinutes)->toBe(60)
        ->and($report->days[5]->sundayWork)->toBeTrue()
        ->and($report->days[6]->sundayWork)->toBeTrue()
        ->and(wteHasIssue($report->days[6]->issues, 'art. 73b OLL 1'))->toBeTrue()
        ->and($report->issues)->toContain("Registrazione semplificata senza data dell'accordo (art. 73b OLL 1).");
});

test('the waiver is always reported as a point to verify', function (): void {
    $profile = new EmployeeTimeProfile(mode: TimeRecordingMode::Waived, agreementDate: '2026-01-01');

    expect(wteHasIssue(SwissWorkingTimeEngine::evaluate(wteContext([], 1, WTE_MONDAY, $profile))->issues, 'art. 73a OLL 1'))->toBeTrue();
});

test('sequence anomalies are reported and an open entry of today is not', function (): void {
    $report = wteEvaluate([
        wteClock(WTE_MONDAY.' 08:00', TimeEventType::Out),
        wteClock('2026-11-03 08:00', TimeEventType::In),
        wteClock('2026-11-03 09:00', TimeEventType::In),
        wteClock('2026-11-03 10:00', TimeEventType::Out),
    ], 2);

    $context = wteContext([wteClock(WTE_MONDAY.' 08:00', TimeEventType::In)]);
    $context->now = wteDate(WTE_MONDAY)->setTime(12, 0);

    expect($report->days[0]->issues)->toContain('Uscita senza entrata')
        ->and($report->days[1]->issues)->toContain('Entrata senza uscita prima di una nuova entrata')
        ->and(SwissWorkingTimeEngine::evaluate($context)->days[0]->issues)->toBe([]);
});

test('breaks shorter than 15 minutes are not breaks, and the span and reduced rest are checked', function (): void {
    $report = wteEvaluate([
        ...wteDay(WTE_MONDAY, '06:00', '10:00', '10:10', '12:00', '18:00', '20:30'),
        ...wteDay('2026-11-03', '06:30', '08:00'),
    ], 2);

    expect($report->days[0]->breakMinutes)->toBe(360)
        ->and(wteHasIssue($report->days[0]->issues, 'superiore a 14 ore'))->toBeTrue()
        ->and(wteHasIssue($report->days[1]->issues, 'ridotto a 10:00 h'))->toBeTrue();
});

test('deleted events are ignored and minutes are formatted as hours', function (): void {
    $report = wteEvaluate([
        ...wteDay(WTE_MONDAY, '08:00', '12:00'),
        new ClockEntry(wteDate(WTE_MONDAY)->setTime(13, 0), TimeEventType::In, isDeleted: true),
    ]);

    expect($report->days[0]->workedMinutes)->toBe(240)
        ->and($report->days[0]->issues)->toBe([])
        ->and(WorkingTimeReport::formatMinutes(510))->toBe('8:30')
        ->and(WorkingTimeReport::formatMinutes(-75))->toBe('-1:15');
});
