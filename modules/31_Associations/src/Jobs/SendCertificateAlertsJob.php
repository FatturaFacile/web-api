<?php

declare(strict_types=1);

namespace Modules\Associations\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Associations\Services\ReminderService;

final class SendCertificateAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext, ReminderService $reminders): void
    {
        $tenantContext->runWithTenant(Tenant::query()->findOrFail($this->tenantId), $reminders->sendCertificateAlerts(...));
    }
}
