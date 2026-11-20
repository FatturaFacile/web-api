<?php

declare(strict_types=1);

use Modules\Forecasting\Domain\Enums\JurisdictionCode;
use Modules\Forecasting\Domain\Services\StatutoryCashOutflowCalendarService;
use Modules\Forecasting\Domain\ValueObjects\StatutoryCashEvent;
use Modules\Forecasting\Domain\ValueObjects\StatutoryProfile;

/**
 * @return array<string, list<string>> code => nominal dates (Y-m-d) of the events of a year
 */
function statutoryNominalDates(JurisdictionCode $jurisdiction, StatutoryProfile $profile, int $year): array
{
    $events = (new StatutoryCashOutflowCalendarService)->eventsBetween($jurisdiction, new DateTimeImmutable("{$year}-01-01"), new DateTimeImmutable("{$year}-12-31"), $profile);
    $byCode = [];

    foreach ($events as $event) {
        $byCode[$event->code][] = $event->nominalDate->format('Y-m-d');
    }

    return $byCode;
}

function italianProfile(): StatutoryProfile
{
    return new StatutoryProfile(20000.0, 10000.0, 14000.0, 12320.0, 60000.0, 0.0, 0.0, true, true);
}

function swissProfile(): StatutoryProfile
{
    return new StatutoryProfile(20000.0, 0.0, 12500.0, 0.0, 0.0, 9000.0, 3000.0, true, false, 11);
}

test('Italy: F24 on the 16th of every month', function (): void {
    $dates = statutoryNominalDates(JurisdictionCode::It, italianProfile(), 2028)['F24'];

    expect($dates)->toHaveCount(12)
        ->and($dates[0])->toBe('2028-01-16')
        ->and($dates[11])->toBe('2028-12-16');
});

test('Italy: quarterly VAT, the VAT advance and the income tax advances', function (): void {
    $events = statutoryNominalDates(JurisdictionCode::It, italianProfile(), 2028);

    expect($events['LIPE_Q1'])->toBe(['2028-05-16'])
        ->and($events['LIPE_Q2'])->toBe(['2028-08-20'])
        ->and($events['LIPE_Q3'])->toBe(['2028-11-16'])
        ->and($events['LIPE_Q4'])->toBe(['2028-03-16'])
        ->and($events['IVA_ADVANCE'])->toBe(['2028-12-27'])
        ->and($events['TAX_ADVANCE_JUN'])->toBe(['2028-06-30'])
        ->and($events['TAX_ADVANCE_NOV'])->toBe(['2028-11-30']);
});

test('Italy: 13th salary in December and 14th in June', function (): void {
    $events = statutoryNominalDates(JurisdictionCode::It, italianProfile(), 2028);

    expect($events['SALARY_13TH'][0])->toStartWith('2028-12')
        ->and($events['SALARY_14TH'][0])->toStartWith('2028-06');

    $without = statutoryNominalDates(JurisdictionCode::It, new StatutoryProfile(20000.0, 10000.0, 14000.0, paysThirteenthSalary: false, paysFourteenthSalary: false), 2028);

    expect($without)->not->toHaveKeys(['SALARY_13TH', 'SALARY_14TH']);
});

test('Italy: income tax advances split 40 and 60 percent', function (): void {
    $events = (new StatutoryCashOutflowCalendarService)->eventsBetween(JurisdictionCode::It, new DateTimeImmutable('2028-06-01'), new DateTimeImmutable('2028-11-30'), italianProfile());
    $amounts = [];

    foreach ($events as $event) {
        $amounts[$event->code] = $event->amount;
    }

    expect($amounts['TAX_ADVANCE_JUN'])->toBe(24000.0)->and($amounts['TAX_ADVANCE_NOV'])->toBe(36000.0);
});

test('Switzerland: MWST two months after each quarter', function (): void {
    $events = statutoryNominalDates(JurisdictionCode::Ch, swissProfile(), 2028);

    expect($events['MWST_Q1'])->toBe(['2028-05-31'])
        ->and($events['MWST_Q2'])->toBe(['2028-08-31'])
        ->and($events['MWST_Q3'])->toBe(['2028-11-30'])
        ->and($events['MWST_Q4'])->toBe(['2028-02-29']); // 2028 is a leap year

    expect(statutoryNominalDates(JurisdictionCode::Ch, swissProfile(), 2027)['MWST_Q4'])->toBe(['2027-02-28']);
});

test('Switzerland: AVS/LPP installments, year-end reconciliation and 13th salary', function (): void {
    $events = statutoryNominalDates(JurisdictionCode::Ch, swissProfile(), 2028);

    expect($events['AVS_LPP'])->toBe(['2028-01-10', '2028-04-10', '2028-07-10', '2028-10-10'])
        ->and($events['AVS_LPP_RECONCILIATION'])->toBe(['2028-01-31'])
        ->and($events['SALARY_13TH'])->toBe(['2028-11-25']);

    $december = new StatutoryProfile(20000.0, 0.0, 12500.0, swissThirteenthSalaryMonth: 12);

    expect(statutoryNominalDates(JurisdictionCode::Ch, $december, 2028)['SALARY_13TH'])->toBe(['2028-12-25']);
});

test('Switzerland does not get Italian events and Italy does not get Swiss ones', function (): void {
    expect(statutoryNominalDates(JurisdictionCode::Ch, swissProfile(), 2028))->not->toHaveKeys(['F24', 'LIPE_Q1', 'SALARY_14TH'])
        ->and(statutoryNominalDates(JurisdictionCode::It, italianProfile(), 2028))->not->toHaveKeys(['MWST_Q1', 'AVS_LPP']);
});

test('a due date on a weekend rolls to Monday and keeps the nominal date', function (): void {
    // 2028-10-16 is a Monday, 2028-07-16 a Sunday, 2028-12-16 a Saturday.
    $events = (new StatutoryCashOutflowCalendarService)->eventsBetween(JurisdictionCode::It, new DateTimeImmutable('2028-07-01'), new DateTimeImmutable('2028-12-31'), new StatutoryProfile(monthlyWithholdingAndContributions: 1000.0));
    $byNominal = [];

    foreach ($events as $event) {
        $byNominal[$event->nominalDate->format('Y-m-d')] = $event->date->format('Y-m-d');
    }

    expect($byNominal['2028-07-16'])->toBe('2028-07-17')
        ->and($byNominal['2028-10-16'])->toBe('2028-10-16')
        ->and($byNominal['2028-12-16'])->toBe('2028-12-18');
});

test('events with no amount are left out and only the requested window is returned', function (): void {
    $service = new StatutoryCashOutflowCalendarService;

    expect($service->eventsBetween(JurisdictionCode::It, new DateTimeImmutable('2028-01-01'), new DateTimeImmutable('2028-12-31'), new StatutoryProfile))->toBe([]);

    $window = $service->eventsBetween(JurisdictionCode::Ch, new DateTimeImmutable('2028-05-01'), new DateTimeImmutable('2028-06-30'), swissProfile());

    expect(array_map(static fn (StatutoryCashEvent $event): string => $event->code, $window))->toBe(['MWST_Q1']);
});
