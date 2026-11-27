<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

use Modules\Observability\Domain\Enums\ConnectorState;

/**
 * Turns the recent probe results into the status of a connector.
 *
 *  - maintenance flag set by the platform administrator → maintenance;
 *  - the last N probes all failed (default 3) → outage; the last one failed → degraded;
 *  - the last probe succeeded but is slower than the latency threshold, the 24 h uptime fell below the floor, or the TLS certificate expires within
 *    the warning window (or already expired) → degraded;
 *  - otherwise operational (also while no probe ran yet).
 */
final class StatusEvaluator
{
    /**
     * @param  list<array{ok: bool, latency_ms: int|null, tls_days_remaining: int|null}>  $recent  Newest first.
     */
    public function evaluate(
        array $recent,
        float $uptime24h,
        int $degradedLatencyMs,
        bool $maintenance,
        int $outageAfterFailures = 3,
        float $degradedUptimePct = 95.0,
        int $tlsWarningDays = 7,
    ): ConnectorState {
        if ($maintenance) {
            return ConnectorState::Maintenance;
        }

        if ($recent === []) {
            return ConnectorState::Operational;
        }

        $last = $recent[0];

        if (! $last['ok']) {
            $window = array_slice($recent, 0, max(1, $outageAfterFailures));
            $allFailed = count($window) >= $outageAfterFailures && array_reduce($window, static fn (bool $carry, array $check): bool => $carry && ! $check['ok'], true);

            return $allFailed ? ConnectorState::Outage : ConnectorState::Degraded;
        }

        if ($last['latency_ms'] !== null && $last['latency_ms'] > $degradedLatencyMs) {
            return ConnectorState::Degraded;
        }

        if ($uptime24h < $degradedUptimePct) {
            return ConnectorState::Degraded;
        }

        if ($last['tls_days_remaining'] !== null && $last['tls_days_remaining'] < $tlsWarningDays) {
            return ConnectorState::Degraded;
        }

        return ConnectorState::Operational;
    }
}
