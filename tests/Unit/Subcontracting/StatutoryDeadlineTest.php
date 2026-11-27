<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Subcontracting\Domain\Enums\DeadlineStatus;
use Modules\Subcontracting\Domain\Enums\LedgerMovementType;
use Modules\Subcontracting\Support\StatutoryDeadline;

it('sets the return deadline one year after the DDT, never past the same day', function (): void {
    expect(StatutoryDeadline::for(CarbonImmutable::parse('2026-03-15'))->toDateString())->toBe('2027-03-15')
        ->and(StatutoryDeadline::for(CarbonImmutable::parse('2026-12-31 18:30:00'))->toDateString())->toBe('2027-12-31')
        ->and(StatutoryDeadline::for(CarbonImmutable::parse('2024-02-29'))->toDateString())->toBe('2025-02-28');
});

it('classifies the deadline as ok, warning, expired or closed', function (): void {
    $today = CarbonImmutable::parse('2026-06-01');

    expect(StatutoryDeadline::status(CarbonImmutable::parse('2026-09-01'), '5', $today))->toBe(DeadlineStatus::Ok)
        ->and(StatutoryDeadline::status(CarbonImmutable::parse('2026-07-01'), '5', $today))->toBe(DeadlineStatus::Warning) // 30 days left
        ->and(StatutoryDeadline::status(CarbonImmutable::parse('2026-06-01'), '5', $today))->toBe(DeadlineStatus::Warning) // last day
        ->and(StatutoryDeadline::status(CarbonImmutable::parse('2026-05-31'), '5', $today))->toBe(DeadlineStatus::Expired)
        ->and(StatutoryDeadline::status(CarbonImmutable::parse('2026-05-31'), '0.0000', $today))->toBe(DeadlineStatus::Closed)
        ->and(StatutoryDeadline::status(null, '5', $today))->toBe(DeadlineStatus::Closed);
});

it('counts the days left, negative once the deadline is over', function (): void {
    $today = CarbonImmutable::parse('2026-06-01 15:00:00');

    expect(StatutoryDeadline::daysLeft(CarbonImmutable::parse('2026-06-11'), $today))->toBe(10)
        ->and(StatutoryDeadline::daysLeft(CarbonImmutable::parse('2026-06-01'), $today))->toBe(0)
        ->and(StatutoryDeadline::daysLeft(CarbonImmutable::parse('2026-05-25'), $today))->toBe(-7);
});

it('starts the statutory clock only with the goods going into the third-party location', function (): void {
    expect(LedgerMovementType::OutboundDispatch->startsStatutoryClock())->toBeTrue()
        ->and(LedgerMovementType::CustomerMaterialReceipt->startsStatutoryClock())->toBeTrue()
        ->and(LedgerMovementType::InboundConsumptionBackflush->startsStatutoryClock())->toBeFalse()
        ->and(LedgerMovementType::ScrapDeclared->startsStatutoryClock())->toBeFalse()
        ->and(LedgerMovementType::UnusedMaterialReturn->startsStatutoryClock())->toBeFalse()
        ->and(LedgerMovementType::CustomerFinishedReturn->startsStatutoryClock())->toBeFalse();
});
