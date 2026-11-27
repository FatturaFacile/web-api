<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The slowest API endpoints of the window ranked by p95: p50, p95, p99, average duration, average queries and database time per request, the error
 * rate and the trace of the slowest request. Percentiles are computed in PHP from the stored samples so they are the same on SQLite and PostgreSQL.
 */
final class TopSlowEndpointsHandler
{
    private const int MAX_SAMPLES_PER_ROUTE = 20000;

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(TopSlowEndpointsQuery $query): array
    {
        $since = now()->subHours($query->hours);

        $routes = DB::table('observability_endpoint_samples')
            ->where('captured_at', '>=', $since)
            ->selectRaw('method, route, COUNT(*) AS requests, AVG(duration_ms) AS avg_ms, AVG(db_query_count) AS avg_queries, AVG(db_time_ms) AS avg_db_ms, SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS errors')
            ->groupBy('method', 'route')
            ->orderByDesc('avg_ms')
            ->limit(max(60, $query->limit * 3))
            ->get();

        $result = [];

        foreach ($routes as $route) {
            $durations = DB::table('observability_endpoint_samples')
                ->where('captured_at', '>=', $since)
                ->where('method', $route->method)
                ->where('route', $route->route)
                ->orderByDesc('captured_at')
                ->limit(self::MAX_SAMPLES_PER_ROUTE)
                ->pluck('duration_ms')
                ->map(static fn ($value): float => (float) $value)
                ->all();

            $slowestTrace = DB::table('observability_endpoint_samples')
                ->where('captured_at', '>=', $since)
                ->where('method', $route->method)
                ->where('route', $route->route)
                ->whereNotNull('trace_id')
                ->orderByDesc('duration_ms')
                ->value('trace_id');

            $result[] = [
                'method' => $route->method,
                'route' => $route->route,
                'requests' => (int) $route->requests,
                'avg_ms' => round((float) $route->avg_ms, 2),
                'p50_ms' => round(self::percentile($durations, 50), 2),
                'p95_ms' => round(self::percentile($durations, 95), 2),
                'p99_ms' => round(self::percentile($durations, 99), 2),
                'avg_db_queries' => round((float) $route->avg_queries, 2),
                'avg_db_ms' => round((float) $route->avg_db_ms, 2),
                'error_rate' => $route->requests > 0 ? round((int) $route->errors / (int) $route->requests * 100, 2) : 0.0,
                'last_trace_id' => $slowestTrace,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);

        return array_slice($result, 0, $query->limit);
    }

    /**
     * Nearest-rank percentile.
     *
     * @param  list<float>  $values
     */
    public static function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $rank = (int) ceil($percentile / 100 * count($values));

        return $values[max(0, min(count($values) - 1, $rank - 1))];
    }
}
