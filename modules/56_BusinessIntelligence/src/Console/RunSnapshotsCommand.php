<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\BusinessIntelligence\Services\SnapshotService;

/**
 * Takes the scheduled snapshots of the saved queries that are due today, for every active company. A failed snapshot is
 * recorded on the query and reported here; the others go on.
 */
final class RunSnapshotsCommand extends Command
{
    protected $signature = 'business-intelligence:run-snapshots {--tenant=* : Limit to these tenants}';

    protected $description = 'Takes the scheduled snapshots of saved business intelligence queries that are due';

    public function handle(TenantContext $tenantContext, SnapshotService $service): int
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
                $failures += $outcome['failed'];
                $this->line("{$tenant->id}: {$outcome['succeeded']} snapshots taken, {$outcome['failed']} failed");
            });
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
