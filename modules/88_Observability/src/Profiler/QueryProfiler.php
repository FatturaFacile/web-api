<?php

declare(strict_types=1);

namespace Modules\Observability\Profiler;

use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Observability\Domain\Enums\PlanStatus;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Privacy\PiiScrubber;
use Modules\Observability\Privacy\SqlSanitizer;
use Throwable;

/**
 * Real-time query listener: measures every query, detects the slow ones (configurable threshold, default 250 ms) and the N+1 patterns, and when
 * the unit of work ends (response sent, job processed, command finished) persists the sanitized captures with their plan and index advice.
 */
final class QueryProfiler
{
    private bool $busy = false;

    public function __construct(
        private readonly SqlSanitizer $sanitizer,
        private readonly PiiScrubber $scrubber,
        private readonly MetricsRegistry $metrics,
        private readonly RequestProfile $profile,
        private readonly ProfilerSettings $settings,
        private readonly ExplainRunner $explainer,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(QueryExecuted $event): void
    {
        if ($this->busy || str_contains($event->sql, 'observability_')) {
            return;
        }

        // The bookkeeping below may itself query (database cache store, settings): those queries are not profiled.
        $this->busy = true;

        try {
            $normalized = $this->sanitizer->normalize($event->sql);
            $fingerprint = $this->sanitizer->fingerprint($normalized);
            $this->metrics->observe(MetricCatalog::DB_QUERY_DURATION, $event->time / 1000, ['connection' => $event->connectionName]);
            $this->profile->recordQuery($fingerprint, $normalized, $event->time, $this->sanitizer->isSelect($normalized));

            if ($event->time >= $this->settings->thresholdMs()) {
                $this->metrics->increment(MetricCatalog::DB_SLOW_QUERIES, ['connection' => $event->connectionName]);
                $this->profile->addSlow($fingerprint, $normalized, $event->sql, array_values($event->bindings), $event->time, $event->connectionName, (int) config('observability.profiler.max_captures_per_request', 20));
            }
        } catch (Throwable) {
            // The profiler must never break a query.
        } finally {
            $this->busy = false;
        }
    }

    /**
     * Persists what the profile collected and clears it.
     */
    public function flush(): void
    {
        if ($this->busy) {
            return;
        }

        if (! config('observability.profiler.capture_enabled', true)) {
            $this->profile->clearQueries();

            return;
        }

        $this->busy = true;

        try {
            $settings = $this->settings->all();
            $tenantId = $this->tenantContext->getTenantId();
            $explained = 0;
            $maxExplains = (int) config('observability.profiler.max_explains_per_request', 3);
            $advisor = new IndexAdvisor((float) config('observability.profiler.advisor_min_total_cost', 1000));

            foreach ($this->profile->slowQueries() as $capture) {
                $status = PlanStatus::Skipped;
                $plan = null;
                $indexes = [];

                if ($settings['explain_enabled'] && $explained < $maxExplains) {
                    $explained++;
                    $result = $this->explainer->explain($capture['connection'], $capture['raw_sql'], $capture['bindings'], (bool) config('observability.profiler.explain_analyze', false), (int) config('observability.profiler.explain_timeout_ms', 2000));
                    $status = $result['status'];
                    $plan = $result['plan'];
                    $indexes = $plan !== null ? $advisor->advise($plan, $capture['connection']) : [];
                }

                $this->insert('slow', $capture['fingerprint'], $capture['sql'], $capture['ms'], 1, $capture['connection'], $tenantId, $status, $plan, $indexes);
            }

            foreach ($this->profile->repeated($settings['n_plus_one_threshold']) as $fingerprint => $entry) {
                $this->insert('n_plus_one', $fingerprint, $entry['sql'], $entry['total_ms'], $entry['count'], null, $tenantId, PlanStatus::Skipped, null, []);
            }

            if ($this->profile->dbMs > 0 && $tenantId !== null) {
                $this->metrics->incrementForTenant(MetricCatalog::TENANT_CONSUMPTION, $tenantId, ['resource' => 'db_seconds'], $this->profile->dbMs / 1000);
            }
        } catch (Throwable) {
            // see handle()
        } finally {
            $this->profile->clearQueries();
            $this->busy = false;
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $plan
     * @param  list<array{table: string, columns: list<string>, sql: string, reason: string}>  $indexes
     */
    private function insert(string $kind, string $fingerprint, string $sql, float $ms, int $count, ?string $connection, ?string $tenantId, PlanStatus $status, ?array $plan, array $indexes): void
    {
        DB::table('observability_slow_queries')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'kind' => $kind,
            'fingerprint' => $fingerprint,
            // The statement is already literal-free; the scrubber is a second net for identifiers that look like personal data.
            'sql_text' => Str::limit($this->scrubber->scrubString($sql), 4000, ''),
            'duration_ms' => round($ms, 3),
            'execution_count' => $count,
            'connection_name' => $connection,
            'trace_id' => $this->profile->traceId,
            'origin_type' => $this->profile->originType,
            'origin' => $this->profile->origin !== null ? Str::limit($this->profile->origin, 190, '') : null,
            'plan_status' => $status->value,
            'plan' => $plan !== null ? json_encode($plan, JSON_THROW_ON_ERROR) : null,
            'suggested_indexes' => $indexes !== [] ? json_encode($indexes, JSON_THROW_ON_ERROR) : null,
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
