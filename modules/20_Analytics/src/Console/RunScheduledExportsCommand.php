<?php

declare(strict_types=1);

namespace Modules\Analytics\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Analytics\Services\ScheduledExportService;

/**
 * Runs the scheduled exports due today for every active company, then removes the stored files whose link has expired.
 * A failed schedule is recorded on the schedule and reported here; the others go on.
 */
final class RunScheduledExportsCommand extends Command
{
    protected $signature = 'analytics:run-scheduled-exports {--tenant=* : Limit to these tenants}';

    protected $description = 'Runs the scheduled report exports that are due and removes the expired export files';

    public function handle(TenantContext $tenantContext, ScheduledExportService $service): int
    {
        $failures = 0;
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $service, &$failures): void {
                $outcome = $service->runDue();
                $purged = $service->purgeExpired();
                $failures += $outcome['failed'];
                $this->line("{$tenant->id}: {$outcome['succeeded']} exports delivered, {$outcome['failed']} failed, {$purged} files removed");
            });
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
