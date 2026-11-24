<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Domain\Enums\LeaveTypeCode;
use Modules\LeaveManagement\Domain\ValueObjects\InpsProtocolNumber;
use Modules\LeaveManagement\Exceptions\LeaveManagementException;
use Modules\LeaveManagement\Services\LeaveIndemnityCalculatorService;

test('InpsProtocolNumber enforces 9-character alphanumeric PUC and normalizes uppercase', function (): void {
    $puc = InpsProtocolNumber::fromString(' ab12345cd ');

    expect($puc->value)->toBe('AB12345CD');
    expect(fn () => InpsProtocolNumber::fromString('SHORT12'))->toThrow(LeaveManagementException::class);
    expect(fn () => InpsProtocolNumber::fromString('TOO_LONG_123'))->toThrow(LeaveManagementException::class);
    expect(fn () => InpsProtocolNumber::fromString('AB12-3456'))->toThrow(LeaveManagementException::class);
});

test('Italian sick leave indemnity computes 3-day carenza and INPS days 4-20 and 21+', function (): void {
    $calc = new LeaveIndemnityCalculatorService;

    $breakdown = $calc->calculate(
        country: LeaveCountry::IT,
        leaveType: LeaveTypeCode::SickLeave_Malattia,
        startDate: CarbonImmutable::parse('2026-05-01'),
        endDate: CarbonImmutable::parse('2026-05-25'),
    );

    expect($breakdown->regime)->toBe('it_inps_sick')
        ->and($breakdown->totalCalendarDays)->toBe(25)
        ->and($breakdown->employerWaitingDays)->toBe(3)
        ->and($breakdown->tier1InsurerDays)->toBe(17)
        ->and($breakdown->tier1InsurerPercent)->toBe(50.0)
        ->and($breakdown->tier2InsurerDays)->toBe(5)
        ->and($breakdown->tier2InsurerPercent)->toBe(66.66);
});

test('Swiss IJM/KTG Wartefrist, LAINF/SUVA 80% day 3+, and IPG/EO calculate accurately', function (): void {
    $calc = new LeaveIndemnityCalculatorService;

    $ijm = $calc->calculate(
        country: LeaveCountry::CH,
        leaveType: LeaveTypeCode::SickLeave_Malattia,
        startDate: CarbonImmutable::parse('2026-01-01'),
        endDate: CarbonImmutable::parse('2026-02-14'),
        ijmKtgWaitingDaysOverride: 30,
    );

    expect($ijm->regime)->toBe('ch_ijm_ktg')
        ->and($ijm->totalCalendarDays)->toBe(45)
        ->and($ijm->employerWaitingDays)->toBe(30)
        ->and($ijm->tier1InsurerDays)->toBe(15)
        ->and($ijm->tier1InsurerPercent)->toBe(80.0);

    $suva = $calc->calculate(
        country: LeaveCountry::CH,
        leaveType: LeaveTypeCode::Accident_Infortunio_SUVA_INAIL,
        startDate: CarbonImmutable::parse('2026-03-01'),
        endDate: CarbonImmutable::parse('2026-03-10'),
        isOccupationalAccident: true,
    );

    expect($suva->regime)->toBe('ch_lainf_suva')
        ->and($suva->employerWaitingDays)->toBe(2)
        ->and($suva->employerIndemnityPercent)->toBe(80.0)
        ->and($suva->tier1InsurerDays)->toBe(8)
        ->and($suva->tier1InsurerPercent)->toBe(80.0);

    $ipg = $calc->calculate(
        country: LeaveCountry::CH,
        leaveType: LeaveTypeCode::CH_MilitaryCivilService_EO_APG,
        startDate: CarbonImmutable::parse('2026-04-01'),
        endDate: CarbonImmutable::parse('2026-04-14'),
    );

    expect($ipg->regime)->toBe('ch_ipg_eo')
        ->and($ipg->tier1InsurerDays)->toBe(14)
        ->and($ipg->tier1InsurerPercent)->toBe(80.0);
});
