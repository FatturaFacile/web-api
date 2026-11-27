<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The slowest SQL statements of the window, grouped by fingerprint: executions, average and worst time, the capture of the worst execution with its
 * plan, the N+1 flag and the suggested indexes.
 */
final class TopSlowQueriesHandler
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(TopSlowQueriesQuery $query): array
    {
        $since = now()->subHours($query->hours);

        $groups = DB::table('observability_slow_queries')
            ->where('captured_at', '>=', $since)
            ->selectRaw('fingerprint, kind, COUNT(*) AS executions, SUM(execution_count) AS total_executions, AVG(duration_ms) AS avg_ms, MAX(duration_ms) AS max_ms, MAX(captured_at) AS last_captured_at')
            ->groupBy('fingerprint', 'kind')
            ->orderByDesc('max_ms')
            ->limit($query->limit)
            ->get();

        $result = [];

        foreach ($groups as $group) {
            $worst = DB::table('observability_slow_queries')
                ->where('captured_at', '>=', $since)
                ->where('fingerprint', $group->fingerprint)
                ->where('kind', $group->kind)
                ->orderByDesc('duration_ms')
                ->first();

            if ($worst === null) {
                continue;
            }

            $nPlusOne = DB::table('observability_slow_queries')
                ->where('captured_at', '>=', $since)
                ->where('fingerprint', $group->fingerprint)
                ->where('kind', 'n_plus_one')
                ->max('execution_count');

            $result[] = [
                'fingerprint' => $group->fingerprint,
                'sql' => $worst->sql_text,
                'kind' => $group->kind,
                'executions' => $group->kind === 'n_plus_one' ? (int) $group->total_executions : (int) $group->executions,
                'avg_ms' => round((float) $group->avg_ms, 3),
                'max_ms' => round((float) $group->max_ms, 3),
                'last_captured_at' => Carbon::parse($group->last_captured_at)->toIso8601String(),
                'tenant_id' => $worst->tenant_id,
                'trace_id' => $worst->trace_id,
                'origin_type' => $worst->origin_type,
                'origin' => $worst->origin,
                'n_plus_one' => $nPlusOne !== null || $group->kind === 'n_plus_one',
                'n_plus_one_count' => (int) ($nPlusOne ?? ($group->kind === 'n_plus_one' ? $worst->execution_count : 0)),
                'plan_status' => $worst->plan_status,
                'plan' => $this->decode($worst->plan),
                'suggested_indexes' => $this->decode($worst->suggested_indexes) ?? [],
            ];
        }

        return $result;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
