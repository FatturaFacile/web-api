<?php

declare(strict_types=1);

namespace Modules\DataExport\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\DataExport\Services\ExportJobService;

/**
 * Queues the scheduled data exports that are due for every active company, then removes the files past their retention.
 */
final class RunScheduledDataExportsCommand extends Command
{
    protected $signature = 'exports:run-scheduled {--tenant=* : Limit to these tenants}';

    protected $description = 'Queues the scheduled data exports that are due and removes the expired export files';

    public function handle(TenantContext $tenantContext, ExportJobService $service): int
    {
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $service): void {
                $queued = $service->runDueSchedules();
                $purged = $service->purgeExpired();
                $this->line("{$tenant->id}: {$queued} exports queued, {$purged} files removed");
            });
        }

        return self::SUCCESS;
    }
}
