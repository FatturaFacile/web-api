<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\LeaveManagement\Exceptions\LeaveManagementException;
use Modules\LeaveManagement\Services\HolidayCatalogService;

test('Swiss holiday catalog covers all 26 cantons with canton-specific holidays', function (): void {
    $catalog = new HolidayCatalogService;

    expect(HolidayCatalogService::SWISS_CANTONS)->toHaveCount(26);

    foreach (HolidayCatalogService::SWISS_CANTONS as $canton) {
        $holidays = $catalog->generateSwiss(2026, $canton);
        $dates = array_column(array_map(static fn ($h): array => $h->toArray(), $holidays), 'date');

        expect($dates)->toContain('2026-01-01', '2026-08-01', '2026-12-25');
    }

    $tiDates = array_column(array_map(static fn ($h): array => $h->toArray(), $catalog->generateSwiss(2026, 'TI')), 'date');
    expect($tiDates)->toContain('2026-06-29', '2026-03-19', '2026-12-08')
        ->not->toContain('2026-04-03'); // Good Friday not statutory in TI

    $geDates = array_column(array_map(static fn ($h): array => $h->toArray(), $catalog->generateSwiss(2026, 'GE')), 'date');
    expect($geDates)->toContain('2026-12-31', '2026-09-10'); // Restauration + Jeûne genevois

    $zhDates = array_column(array_map(static fn ($h): array => $h->toArray(), $catalog->generateSwiss(2026, 'ZH')), 'date');
    expect($zhDates)->toContain('2026-01-02', '2026-05-01', '2026-04-03');
});

test('Italian holiday catalog includes national holidays, municipal patron saints, and Sunday holiday flags', function (): void {
    $catalog = new HolidayCatalogService;

    $milano = array_map(static fn ($h): array => $h->toArray(), $catalog->generateItalian(2026, 'Milano'));
    $milanoDates = array_column($milano, 'date');
    expect($milanoDates)->toContain('2026-04-25', '2026-06-02', '2026-12-07');

    // 2026-11-01 (Ognissanti) falls on Sunday -> paid_compensation_applicable = true
    $ognissanti = collect($milano)->firstWhere('date', '2026-11-01');
    expect($ognissanti)->not->toBeNull()
        ->and($ognissanti['is_sunday'])->toBeTrue()
        ->and($ognissanti['paid_compensation_applicable'])->toBeTrue();

    $roma = array_map(static fn ($h): array => $h->toArray(), $catalog->generateItalian(2026, 'Roma'));
    expect(array_column($roma, 'date'))->toContain('2026-06-29');

    $custom = array_map(static fn ($h): array => $h->toArray(), $catalog->generateItalian(2026, 'BergamoCustom', '09-14', 'Santa Croce'));
    expect(array_column($custom, 'date'))->toContain('2026-09-14');
});

test('invalid Swiss canton throws domain exception and working days exclude holidays', function (): void {
    $catalog = new HolidayCatalogService;

    expect(fn () => $catalog->generateSwiss(2026, 'XX'))->toThrow(LeaveManagementException::class);

    $workingDays = $catalog->countWorkingDays(
        CarbonImmutable::parse('2026-12-07'),
        CarbonImmutable::parse('2026-12-11'),
        ['2026-12-07', '2026-12-08']
    );

    expect($workingDays)->toBe(3);
});
