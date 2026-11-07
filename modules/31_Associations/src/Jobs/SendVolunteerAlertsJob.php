<?php

declare(strict_types=1);

namespace Modules\Associations\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Associations\Services\VolunteerAlertService;

/**
 * Alerts about shifts without enough volunteers and about expiring trainings and insurance coverage.
 */
final class SendVolunteerAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext, VolunteerAlertService $alerts): void
    {
        $tenantContext->runWithTenant(Tenant::query()->findOrFail($this->tenantId), $alerts->send(...));
    }
}
