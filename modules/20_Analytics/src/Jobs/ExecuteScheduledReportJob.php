<?php

declare(strict_types=1);

namespace Modules\Analytics\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Services\ScheduledExportService;

/**
 * Generates the report of one schedule in the locale and format it asks for and delivers it to its channels, inside the
 * company of the schedule. The next run of a cron schedule was already moved when the dispatcher claimed it (and a manual
 * "run now" leaves the calendar alone), so the job never touches it.
 */
final class ExecuteScheduledReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $tenantId, public readonly string $scheduleId)
    {
        $this->afterCommit();
    }

    public function handle(TenantContext $tenantContext, ScheduledExportService $service): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null || $tenant->is_maintenance) {
            return;
        }

        $tenantContext->runWithTenant($tenant, function () use ($service): void {
            $schedule = ExportSchedule::query()->find($this->scheduleId);

            if ($schedule !== null) {
                $service->runSchedule($schedule, advance: false);
            }
        });
    }
}
