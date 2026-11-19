<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Analytics\Jobs\ExecuteScheduledReportJob;
use Modules\Analytics\Services\ScheduledExportService;

/**
 * Runs every minute: queues the cron schedules that are due (`is_active` and `next_run_at <= now`) of every active
 * company. A schedule is claimed (its next run moved forward) before its job is queued, so a long run or a second
 * dispatcher never queues it twice.
 */
final class DispatchScheduledReportsCommand extends Command
{
    protected $signature = 'analytics:dispatch-scheduled-reports {--tenant=* : Limit to these tenants}';

    protected $description = 'Queues the cron-scheduled report deliveries that are due';

    public function handle(TenantContext $tenantContext, ScheduledExportService $service): int
    {
        $queued = 0;
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $service, &$queued): void {
                foreach ($service->claimDueCron() as $schedule) {
                    ExecuteScheduledReportJob::dispatch((string) $tenant->id, (string) $schedule->id);
                    $queued++;
                }
            });
        }

        $this->line("{$queued} scheduled reports queued");

        return self::SUCCESS;
    }
}
