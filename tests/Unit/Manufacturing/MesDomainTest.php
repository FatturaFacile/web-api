<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\PhaseAction;
use Modules\Manufacturing\Enums\PhaseStatus;
use Modules\Manufacturing\Enums\SuspensionReason;
use Modules\Manufacturing\Enums\TimeCategory;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Mes\Time\PhaseTimeCalculator;
use Modules\Manufacturing\Mes\Time\PhaseTimeSummary;
use Modules\Manufacturing\Mes\ValueObjects\LotCode;
use Modules\Manufacturing\Models\MfgMesTimeLog;

/**
 * An in-memory interval.
 */
function mesLog(TimeCategory $category, string $start, ?string $end, ?string $operator = 'op-1', bool $attended = true, ?SuspensionReason $reason = null): MfgMesTimeLog
{
    return new MfgMesTimeLog([
        'category' => $category,
        'operator_id' => $operator,
        'machine_attended' => $attended,
        'suspension_reason' => $reason,
        'started_at' => CarbonImmutable::parse($start),
        'ended_at' => $end !== null ? CarbonImmutable::parse($end) : null,
    ]);
}

test('man time is setup plus attended run, machine time is setup plus every run, suspensions are downtime', function (): void {
    $summary = (new PhaseTimeCalculator)->summarize([
        mesLog(TimeCategory::Setup, '2026-01-05 08:00:00', '2026-01-05 08:20:00'),
        mesLog(TimeCategory::Run, '2026-01-05 08:20:00', '2026-01-05 09:00:00'),
        mesLog(TimeCategory::Suspension, '2026-01-05 09:00:00', '2026-01-05 09:10:00', reason: SuspensionReason::Break),
        mesLog(TimeCategory::Run, '2026-01-05 09:10:00', '2026-01-05 10:10:00', attended: false),
    ], CarbonImmutable::parse('2026-01-05 12:00:00'));

    expect($summary->setupSeconds)->toBe(1200)
        ->and($summary->attendedRunSeconds)->toBe(2400)
        ->and($summary->unattendedRunSeconds)->toBe(3600)
        ->and($summary->manSeconds())->toBe(3600)
        ->and($summary->machineSeconds())->toBe(7200)
        ->and($summary->downtimeSeconds)->toBe(600)
        ->and($summary->downtimeSecondsByReason)->toBe(['break' => 600])
        ->and($summary->toArray()['man_minutes'])->toBe('60.00');
});

test('an open interval runs up to the given instant and a suspended phase accrues no production time', function (): void {
    $open = [
        mesLog(TimeCategory::Run, '2026-01-05 08:00:00', '2026-01-05 09:00:00'),
        mesLog(TimeCategory::Suspension, '2026-01-05 09:00:00', null, reason: SuspensionReason::MachineBreakdown),
    ];

    $early = (new PhaseTimeCalculator)->summarize($open, CarbonImmutable::parse('2026-01-05 09:30:00'));
    $late = (new PhaseTimeCalculator)->summarize($open, CarbonImmutable::parse('2026-01-05 13:00:00'));

    expect($early->downtimeSeconds)->toBe(1800)
        ->and($late->downtimeSeconds)->toBe(14400)
        ->and($early->manSeconds())->toBe(3600)
        ->and($late->manSeconds())->toBe(3600)
        ->and($late->machineSeconds())->toBe(3600);
});

test('man time is split by operator and an interval that ends before it starts counts nothing', function (): void {
    $summary = (new PhaseTimeCalculator)->summarize([
        mesLog(TimeCategory::Run, '2026-01-05 08:00:00', '2026-01-05 10:00:00', 'op-1'),
        mesLog(TimeCategory::Run, '2026-01-05 10:00:00', '2026-01-05 11:30:00', 'op-2'),
        mesLog(TimeCategory::Setup, '2026-01-05 12:00:00', '2026-01-05 11:00:00', 'op-3'),
        mesLog(TimeCategory::Run, '2026-01-05 11:30:00', '2026-01-05 12:00:00', null),
    ], CarbonImmutable::parse('2026-01-05 12:00:00'));

    expect($summary->manSecondsByOperator)->toBe(['op-1' => 7200, 'op-2' => 5400, '' => 1800])
        ->and($summary->setupSeconds)->toBe(0)
        ->and(PhaseTimeSummary::minutes(90))->toBe('1.50');
});

test('the transition table allows exactly the documented actions in every state', function (): void {
    $expected = [
        'available' => ['take_over'],
        'assigned' => ['take_over', 'start_setup', 'start_production'],
        'setup' => ['start_production', 'suspend', 'consume_material'],
        'running' => ['start_production', 'suspend', 'declare_production', 'consume_material', 'close'],
        'suspended' => ['take_over', 'resume', 'declare_production', 'consume_material', 'close'],
        'closed' => [],
    ];

    foreach (PhaseStatus::cases() as $status) {
        expect(array_map(static fn (PhaseAction $action): string => $action->value, $status->allowedActions()))->toBe($expected[$status->value], $status->value);
    }
    expect(PhaseStatus::Closed->hasOpenInterval())->toBeFalse()
        ->and(PhaseStatus::Suspended->hasOpenInterval())->toBeTrue()
        ->and(MachineMode::from('unattended'))->toBe(MachineMode::Unattended);

    expect(fn () => PhaseAction::Close->assertAllowedFrom(PhaseStatus::Assigned))->toThrow(ManufacturingOperationException::class, 'not allowed while the phase is assigned');
});

test('lot codes are trimmed, upper-cased and limited to barcode characters', function (): void {
    expect(LotCode::fromString('  al-2026/001 ')->toString())->toBe('AL-2026/001');

    foreach (['', '   ', '-lead', 'with space', 'emoji✓', str_repeat('A', 65)] as $bad) {
        expect(fn () => LotCode::fromString($bad))->toThrow(ManufacturingOperationException::class);
    }
});
