<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AssetManagement\Services\AssetCoverageAlertService;

/**
 * Daily alerts of the warranties and insurances of a tenant's assets expiring within the horizon.
 */
final class NotifyExpiringAssetCoverageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $withinDays = 30
    ) {
        $this->afterCommit();
    }

    /**
     * Sends the alerts not sent yet.
     */
    public function handle(AssetCoverageAlertService $alerts): void
    {
        $alerts->notifyExpiring($this->tenantId, $this->withinDays);
    }
}
