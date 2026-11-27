<?php

declare(strict_types=1);

namespace Modules\Observability\Profiler;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runtime settings of the profiler and of the predictive alerting thresholds: the stored values over the defaults of config/observability.php.
 */
final class ProfilerSettings
{
    public const string KEY = 'profiler';

    private const string CACHE_KEY = 'obs:settings:profiler';

    /** @var list<string> */
    public const array FIELDS = ['slow_query_threshold_ms', 'n_plus_one_threshold', 'explain_enabled', 'alert_endpoint_p95_ms', 'alert_tenant_share_pct', 'alert_connector_latency_ms', 'retention_days'];

    /**
     * Slow-query threshold for the query listener: it must never query the database (the listener runs inside migrations and transactions), so it
     * reads the cached settings and falls back to the configured default.
     */
    public function thresholdMs(): int
    {
        $stored = Cache::get(self::CACHE_KEY);

        return max(1, (int) (is_array($stored) && isset($stored['slow_query_threshold_ms']) ? $stored['slow_query_threshold_ms'] : config('observability.profiler.slow_query_threshold_ms', 250)));
    }

    /**
     * @return array{slow_query_threshold_ms: int, n_plus_one_threshold: int, explain_enabled: bool, alert_endpoint_p95_ms: int, alert_tenant_share_pct: float, alert_connector_latency_ms: int, retention_days: int}
     */
    public function all(): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::CACHE_KEY, 60, fn (): array => $this->stored());
        $defaults = (array) config('observability.profiler', []);
        $value = static fn (string $field): mixed => $stored[$field] ?? $defaults[$field] ?? null;

        return [
            'slow_query_threshold_ms' => max(1, (int) $value('slow_query_threshold_ms')),
            'n_plus_one_threshold' => max(2, (int) $value('n_plus_one_threshold')),
            'explain_enabled' => (bool) $value('explain_enabled'),
            'alert_endpoint_p95_ms' => max(1, (int) $value('alert_endpoint_p95_ms')),
            'alert_tenant_share_pct' => max(1.0, min(100.0, (float) $value('alert_tenant_share_pct'))),
            'alert_connector_latency_ms' => max(1, (int) $value('alert_connector_latency_ms')),
            'retention_days' => max(1, (int) $value('retention_days')),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{slow_query_threshold_ms: int, n_plus_one_threshold: int, explain_enabled: bool, alert_endpoint_p95_ms: int, alert_tenant_share_pct: float, alert_connector_latency_ms: int, retention_days: int}
     */
    public function update(array $values): array
    {
        $payload = array_intersect_key($values, array_flip(self::FIELDS)) + $this->stored();
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $updated = DB::table('observability_settings')->where('key', self::KEY)->update(['value' => $json, 'updated_at' => now()]);

        if ($updated === 0) {
            DB::table('observability_settings')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => null, 'key' => self::KEY, 'value' => $json, 'created_at' => now(), 'updated_at' => now()]);
        }

        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        try {
            $row = DB::table('observability_settings')->where('key', self::KEY)->value('value');
            $decoded = is_string($row) ? json_decode($row, true) : null;

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }
}
