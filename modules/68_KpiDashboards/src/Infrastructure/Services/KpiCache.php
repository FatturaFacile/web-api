<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Pre-aggregation cache of the cockpit payloads, keyed `tenant:{id}:kpi:v{version}:{role}:{hash}`. Invalidating a tenant
 * bumps its version number, which orphans every key at once on any cache store (Redis in production, array in tests)
 * without needing tag support. The raw aggregates are plain grouped queries over indexed ledger and document tables, so
 * the same logic runs on PostgreSQL and SQLite; there are no engine-specific materialized views to refresh.
 */
final class KpiCache
{
    private const int TtlSeconds = 300;

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $tenantId, string $scope, string $hash, Closure $compute, ?int $ttl = null): mixed
    {
        return Cache::remember($this->key($tenantId, $scope, $hash), $ttl ?? self::TtlSeconds, $compute);
    }

    /**
     * Drops every cached payload of the tenant.
     */
    public function invalidate(string $tenantId): void
    {
        Cache::forever($this->versionKey($tenantId), $this->version($tenantId) + 1);
    }

    public function version(string $tenantId): int
    {
        return (int) Cache::get($this->versionKey($tenantId), 1);
    }

    private function key(string $tenantId, string $scope, string $hash): string
    {
        return "tenant:{$tenantId}:kpi:v{$this->version($tenantId)}:{$scope}:{$hash}";
    }

    private function versionKey(string $tenantId): string
    {
        return "tenant:{$tenantId}:kpi:version";
    }
}
