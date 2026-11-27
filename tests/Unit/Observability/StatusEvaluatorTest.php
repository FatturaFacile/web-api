<?php

declare(strict_types=1);

use Modules\Observability\Connectors\StatusEvaluator;
use Modules\Observability\Domain\Enums\ConnectorState;

function obsCheck(bool $ok, ?int $latency = 120, ?int $tlsDays = 90): array
{
    return ['ok' => $ok, 'latency_ms' => $latency, 'tls_days_remaining' => $tlsDays];
}

it('is operational without probes and with a healthy last probe', function (): void {
    $evaluator = new StatusEvaluator;

    expect($evaluator->evaluate([], 100.0, 3000, false))->toBe(ConnectorState::Operational)
        ->and($evaluator->evaluate([obsCheck(true)], 100.0, 3000, false))->toBe(ConnectorState::Operational);
});

it('is in maintenance whatever the probes say', function (): void {
    expect((new StatusEvaluator)->evaluate([obsCheck(false), obsCheck(false), obsCheck(false)], 0.0, 3000, true))->toBe(ConnectorState::Maintenance);
});

it('degrades on the first failure and falls into outage after three consecutive failures', function (): void {
    $evaluator = new StatusEvaluator;

    expect($evaluator->evaluate([obsCheck(false), obsCheck(true)], 50.0, 3000, false))->toBe(ConnectorState::Degraded)
        ->and($evaluator->evaluate([obsCheck(false), obsCheck(false), obsCheck(true)], 50.0, 3000, false))->toBe(ConnectorState::Degraded)
        ->and($evaluator->evaluate([obsCheck(false), obsCheck(false), obsCheck(false)], 0.0, 3000, false))->toBe(ConnectorState::Outage);
});

it('degrades on a slow answer, a low uptime and an expiring certificate', function (): void {
    $evaluator = new StatusEvaluator;

    expect($evaluator->evaluate([obsCheck(true, 3500)], 100.0, 3000, false))->toBe(ConnectorState::Degraded)
        ->and($evaluator->evaluate([obsCheck(true)], 90.0, 3000, false))->toBe(ConnectorState::Degraded)
        ->and($evaluator->evaluate([obsCheck(true, 100, 3)], 100.0, 3000, false))->toBe(ConnectorState::Degraded)
        ->and($evaluator->evaluate([obsCheck(true, 100, -1)], 100.0, 3000, false))->toBe(ConnectorState::Degraded)
        ->and($evaluator->evaluate([obsCheck(true, 3000)], 95.0, 3000, false))->toBe(ConnectorState::Operational);
});

it('ranks the states by severity for the overall status', function (): void {
    expect(ConnectorState::worst([]))->toBe(ConnectorState::Operational)
        ->and(ConnectorState::worst([ConnectorState::Operational, ConnectorState::Maintenance]))->toBe(ConnectorState::Maintenance)
        ->and(ConnectorState::worst([ConnectorState::Maintenance, ConnectorState::Degraded]))->toBe(ConnectorState::Degraded)
        ->and(ConnectorState::worst([ConnectorState::Degraded, ConnectorState::Outage, ConnectorState::Operational]))->toBe(ConnectorState::Outage)
        ->and(ConnectorState::Outage->gauge())->toBe(0)
        ->and(ConnectorState::Operational->gauge())->toBe(2);
});
