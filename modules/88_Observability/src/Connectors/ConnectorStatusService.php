<?php

declare(strict_types=1);

namespace Modules\Observability\Connectors;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Observability\Domain\Enums\ConnectorState;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Profiler\ProfilerSettings;

/**
 * Persists the probe results, derives the rolling status (operational, degraded, outage, maintenance) with the 24 h / 7 d uptime and serves the
 * views of the platform administrator (with errors, TLS and the individual checks) and of the tenants (status, uptime, latency, hourly history).
 */
final class ConnectorStatusService
{
    public function __construct(
        private readonly ConnectorCatalog $catalog,
        private readonly StatusEvaluator $evaluator,
        private readonly ProfilerSettings $settings,
        private readonly MetricsRegistry $metrics,
    ) {}

    public function record(string $key, ProbeResult $result): void
    {
        DB::table('observability_connector_checks')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => null,
            'connector_key' => $key,
            'ok' => $result->ok,
            'http_status' => $result->httpStatus,
            'latency_ms' => $result->latencyMs,
            'tls_days_remaining' => $result->tlsDaysRemaining,
            'tls_valid' => $result->tlsValid,
            'error' => $result->error,
            'checked_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->refresh($key);
    }

    /**
     * Recomputes and stores the status row of a connector from its recent checks.
     */
    public function refresh(string $key): ConnectorState
    {
        $now = now();
        $row = DB::table('observability_connector_statuses')->where('connector_key', $key)->first();
        $maintenance = (bool) ($row->maintenance ?? false);

        $checks = DB::table('observability_connector_checks')
            ->where('connector_key', $key)
            ->where('checked_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('checked_at')
            ->get(['ok', 'latency_ms', 'tls_days_remaining', 'checked_at']);

        $since24h = $now->copy()->subDay();
        $window24h = $checks->filter(fn (object $check): bool => Carbon::parse($check->checked_at)->gte($since24h));
        $uptime24h = $this->uptime($window24h->pluck('ok')->all());
        $uptime7d = $this->uptime($checks->pluck('ok')->all());
        $latencies = $window24h->where('ok', true)->pluck('latency_ms')->filter(static fn ($value): bool => $value !== null);

        $recent = $checks->take(max(3, (int) config('observability.connectors.outage_after_failures', 3)))->map(static fn (object $check): array => [
            'ok' => (bool) $check->ok,
            'latency_ms' => $check->latency_ms !== null ? (int) $check->latency_ms : null,
            'tls_days_remaining' => $check->tls_days_remaining !== null ? (int) $check->tls_days_remaining : null,
        ])->values()->all();

        $state = $this->evaluator->evaluate(
            $recent,
            $uptime24h,
            $this->settings->all()['alert_connector_latency_ms'],
            $maintenance,
            (int) config('observability.connectors.outage_after_failures', 3),
            (float) config('observability.connectors.degraded_uptime_pct', 95.0),
            (int) config('observability.connectors.tls_warning_days', 7),
        );

        $last = $checks->first();
        $lastFailure = $last !== null && ! $last->ok ? DB::table('observability_connector_checks')->where('connector_key', $key)->where('ok', false)->orderByDesc('checked_at')->value('error') : null;
        $changed = ($row->status ?? null) !== $state->value;

        $values = [
            'status' => $state->value,
            'uptime_24h' => $uptime24h,
            'uptime_7d' => $uptime7d,
            'avg_latency_ms' => $latencies->isEmpty() ? null : (int) round($latencies->avg()),
            'last_latency_ms' => $last?->latency_ms,
            'last_checked_at' => $last?->checked_at,
            'last_error' => $lastFailure,
            'tls_days_remaining' => $last?->tls_days_remaining,
            'updated_at' => $now,
        ] + ($changed ? ['status_changed_at' => $now] : []);

        if ($row === null) {
            DB::table('observability_connector_statuses')->insert($values + ['id' => (string) Str::uuid7(), 'tenant_id' => null, 'connector_key' => $key, 'maintenance' => false, 'maintenance_message' => null, 'created_at' => $now, 'status_changed_at' => $now]);
        } else {
            DB::table('observability_connector_statuses')->where('connector_key', $key)->update($values);
        }

        $this->publishMetrics($key, $state, $last);

        if ($changed && $row !== null) {
            Log::channel(config('logging.default'))->warning('Institutional connector status changed', ['connector' => $key, 'from' => $row->status, 'to' => $state->value]);
        }

        return $state;
    }

    public function setMaintenance(string $key, bool $maintenance, ?string $message): void
    {
        $row = DB::table('observability_connector_statuses')->where('connector_key', $key)->first();

        if ($row === null) {
            DB::table('observability_connector_statuses')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => null, 'connector_key' => $key, 'status' => 'operational', 'maintenance' => $maintenance, 'maintenance_message' => $maintenance ? $message : null, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('observability_connector_statuses')->where('connector_key', $key)->update(['maintenance' => $maintenance, 'maintenance_message' => $maintenance ? $message : null, 'updated_at' => now()]);
        }

        $this->refresh($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function view(string $key, bool $admin): array
    {
        $connector = $this->catalog->find($key);
        $row = DB::table('observability_connector_statuses')->where('connector_key', $key)->first();

        return [
            'key' => $key,
            'name_code' => $connector['name_code'] ?? 'OBSERVABILITY.CONNECTOR.'.strtoupper($key),
            'country' => $connector['country'] ?? 'EU',
            'group' => $connector['group'] ?? 'fiscal',
            'status' => $row->status ?? ConnectorState::Operational->value,
            'uptime_24h' => (float) ($row->uptime_24h ?? 100),
            'uptime_7d' => (float) ($row->uptime_7d ?? 100),
            'avg_latency_ms' => isset($row->avg_latency_ms) ? (int) $row->avg_latency_ms : null,
            'last_latency_ms' => isset($row->last_latency_ms) ? (int) $row->last_latency_ms : null,
            'last_checked_at' => isset($row->last_checked_at) ? Carbon::parse($row->last_checked_at)->toIso8601String() : null,
            'last_error' => $admin ? ($row->last_error ?? null) : null,
            'tls_days_remaining' => $admin && isset($row->tls_days_remaining) ? (int) $row->tls_days_remaining : null,
            'maintenance_message' => ($row->maintenance ?? false) ? ($row->maintenance_message ?? null) : null,
            'history' => $this->history($key),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $admin): array
    {
        return array_map(fn (string $key): array => $this->view($key, $admin), $this->catalog->keys());
    }

    /**
     * @return array{overall: string, checked_at: string|null, connectors: list<array<string, mixed>>}
     */
    public function summary(bool $admin = false): array
    {
        $connectors = $this->all($admin);
        $states = array_map(static fn (array $connector): ConnectorState => ConnectorState::from((string) $connector['status']), $connectors);
        $checked = array_filter(array_column($connectors, 'last_checked_at'));

        return [
            'overall' => ConnectorState::worst($states)->value,
            'checked_at' => $checked === [] ? null : max($checked),
            'connectors' => $connectors,
        ];
    }

    /**
     * The individual checks of a connector (latency history for the administrator).
     *
     * @return list<array{checked_at: string, status: string, latency_ms: int|null, http_status: int|null, error: string|null}>
     */
    public function checks(string $key, int $hours): array
    {
        return DB::table('observability_connector_checks')
            ->where('connector_key', $key)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->orderBy('checked_at')
            ->get()
            ->map(static fn (object $check): array => [
                'checked_at' => Carbon::parse($check->checked_at)->toIso8601String(),
                'status' => $check->ok ? 'operational' : 'outage',
                'latency_ms' => $check->latency_ms !== null ? (int) $check->latency_ms : null,
                'http_status' => $check->http_status !== null ? (int) $check->http_status : null,
                'error' => $check->error,
            ])->values()->all();
    }

    /**
     * Hourly buckets of the last 24 hours, oldest first: operational, degraded (some failures or slow), outage (most failed) or unknown (no probe).
     *
     * @return list<array{bucket: string, status: string}>
     */
    public function history(string $key): array
    {
        $end = now()->startOfHour();
        $start = $end->copy()->subHours(23);
        $slowMs = $this->settings->all()['alert_connector_latency_ms'];

        $byHour = DB::table('observability_connector_checks')
            ->where('connector_key', $key)
            ->where('checked_at', '>=', $start)
            ->get(['ok', 'latency_ms', 'checked_at'])
            ->groupBy(static fn (object $check): string => Carbon::parse($check->checked_at)->startOfHour()->toIso8601String());

        $buckets = [];

        for ($hour = $start->copy(); $hour->lte($end); $hour->addHour()) {
            $label = $hour->toIso8601String();
            $checks = $byHour->get($label);

            if ($checks === null || $checks->isEmpty()) {
                $buckets[] = ['bucket' => $label, 'status' => 'unknown'];

                continue;
            }

            $failed = $checks->where('ok', false)->count();
            $slow = $checks->filter(static fn (object $check): bool => $check->latency_ms !== null && $check->latency_ms > $slowMs)->count();
            $status = match (true) {
                $failed * 2 > $checks->count() => ConnectorState::Outage->value,
                $failed > 0 || $slow > 0 => ConnectorState::Degraded->value,
                default => ConnectorState::Operational->value,
            };
            $buckets[] = ['bucket' => $label, 'status' => $status];
        }

        return $buckets;
    }

    /**
     * @param  list<mixed>  $oks
     */
    private function uptime(array $oks): float
    {
        if ($oks === []) {
            return 100.0;
        }

        return round(count(array_filter($oks, static fn ($ok): bool => (bool) $ok)) / count($oks) * 100, 2);
    }

    private function publishMetrics(string $key, ConnectorState $state, ?object $last): void
    {
        $this->metrics->setGauge(MetricCatalog::CONNECTOR_STATUS, (float) $state->gauge(), ['connector' => $key]);

        if ($last !== null) {
            $this->metrics->setGauge(MetricCatalog::CONNECTOR_UP, $last->ok ? 1.0 : 0.0, ['connector' => $key]);

            if ($last->latency_ms !== null) {
                $this->metrics->setGauge(MetricCatalog::CONNECTOR_LATENCY, (float) $last->latency_ms / 1000, ['connector' => $key]);
            }
        }
    }
}
