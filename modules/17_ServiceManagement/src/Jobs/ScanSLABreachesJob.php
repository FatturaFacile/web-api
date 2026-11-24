<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ServiceManagement\Contracts\SLAManagementServiceInterface;
use Modules\ServiceManagement\Services\Helpdesk\HelpdeskSlaMonitor;

/**
 * Scheduled SLA check of a tenant: warnings and breaches of the first response and resolution targets, with escalation
 * and notifications, then the earlier breach flag for tickets the helpdesk monitor does not track.
 */
final class ScanSLABreachesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null
    ) {
        $this->afterCommit();
    }

    public function handle(SLAManagementServiceInterface $slaService, HelpdeskSlaMonitor $monitor): void
    {
        if ($this->tenantId !== null) {
            $monitor->scan($this->tenantId);
        }

        $slaService->checkAndRecordBreaches();
    }
}
