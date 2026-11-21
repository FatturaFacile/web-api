<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Handlers;

use Illuminate\Support\Facades\Cache;
use Modules\KpiDashboards\Application\Commands\RefreshAnalyticalCubesCommand;
use Modules\KpiDashboards\Infrastructure\Services\KpiCache;

final class RefreshAnalyticalCubesHandler
{
    private const int DebounceSeconds = 5;

    public function __construct(private readonly KpiCache $cache) {}

    /**
     * @return array{refreshed: bool, debounced: bool, cache_version: int}
     */
    public function handle(RefreshAnalyticalCubesCommand $command): array
    {
        // A burst of refresh requests (postings, a button hammered) collapses into one invalidation.
        if (! Cache::add("tenant:{$command->tenantId}:kpi:refresh-debounce", true, self::DebounceSeconds)) {
            return ['refreshed' => false, 'debounced' => true, 'cache_version' => $this->cache->version($command->tenantId)];
        }

        $this->cache->invalidate($command->tenantId);

        return ['refreshed' => true, 'debounced' => false, 'cache_version' => $this->cache->version($command->tenantId)];
    }
}
