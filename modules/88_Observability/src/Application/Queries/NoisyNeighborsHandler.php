<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Queries;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Modules\Observability\Profiler\ProfilerSettings;

/**
 * Noisy neighbour detection: the share of the database time of the window consumed by each tenant, flagged above the predictive alert threshold
 * (and a minimum number of requests, so a quiet platform does not alert).
 */
final class NoisyNeighborsHandler
{
    public function __construct(private readonly ProfilerSettings $settings) {}

    /**
     * @return list<array{tenant_id: string, tenant_name: string|null, requests: int, db_ms: float, share_pct: float, flagged: bool}>
     */
    public function handle(NoisyNeighborsQuery $query): array
    {
        $rows = DB::table('observability_endpoint_samples')
            ->whereNotNull('tenant_id')
            ->where('captured_at', '>=', now()->subHours($query->hours))
            ->selectRaw('tenant_id, COUNT(*) AS requests, SUM(db_time_ms) AS db_ms')
            ->groupBy('tenant_id')
            ->orderByDesc('db_ms')
            ->get();

        $total = (float) $rows->sum('db_ms');

        if ($rows->isEmpty()) {
            return [];
        }

        $threshold = $this->settings->all()['alert_tenant_share_pct'];
        $minRequests = (int) config('observability.profiler.noisy_neighbor_min_requests', 50);
        $names = Tenant::withoutGlobalScopes()->whereIn('id', $rows->take($query->limit)->pluck('tenant_id')->all())->pluck('name', 'id');

        return $rows->take($query->limit)->map(function (object $row) use ($total, $threshold, $minRequests, $names): array {
            $share = $total > 0 ? round((float) $row->db_ms / $total * 100, 2) : 0.0;

            return [
                'tenant_id' => (string) $row->tenant_id,
                'tenant_name' => $names->get($row->tenant_id),
                'requests' => (int) $row->requests,
                'db_ms' => round((float) $row->db_ms, 2),
                'share_pct' => $share,
                'flagged' => $share >= $threshold && (int) $row->requests >= $minRequests,
            ];
        })->values()->all();
    }
}
