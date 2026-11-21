<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant scoped cache of the DSS read models. Stores that support tags get one tag per tenant; the others (file, database)
 * get a per-tenant version number in the key, so a mart update invalidates every cached answer of that tenant at once and
 * never touches another tenant.
 */
final class DssCache
{
    /**
     * @template T
     *
     * @param  Closure(): T  $resolver
     * @return T
     */
    public function remember(string $tenantId, string $key, Closure $resolver): mixed
    {
        $ttl = (int) config('analytics_dss.cache_ttl_seconds', 900);

        if ($this->supportsTags()) {
            return Cache::tags([$this->tag($tenantId)])->remember($key, $ttl, $resolver);
        }

        return Cache::remember("dss:{$tenantId}:v{$this->version($tenantId)}:{$key}", $ttl, $resolver);
    }

    public function flush(string $tenantId): void
    {
        if ($this->supportsTags()) {
            Cache::tags([$this->tag($tenantId)])->flush();

            return;
        }

        Cache::forever($this->versionKey($tenantId), $this->version($tenantId) + 1);
    }

    private function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    private function tag(string $tenantId): string
    {
        return "dss:{$tenantId}";
    }

    private function versionKey(string $tenantId): string
    {
        return "dss:{$tenantId}:version";
    }

    private function version(string $tenantId): int
    {
        return (int) Cache::get($this->versionKey($tenantId), 1);
    }
}
