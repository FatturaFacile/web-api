<?php

declare(strict_types=1);

namespace Modules\Marketplace\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Marketplace\Services\HealthService;

/**
 * Checks the health of every enabled connector installation, for every active company. Unhealthy installations are
 * reported in the output; the others go on.
 */
final class CheckHealthCommand extends Command
{
    protected $signature = 'marketplace:check-health {--tenant=* : Limit to these tenants}';

    protected $description = 'Runs the health checks of the enabled marketplace installations';

    public function handle(TenantContext $tenantContext, HealthService $service): int
    {
        $unhealthy = 0;
        $tenants = Tenant::query()
            ->where('status', 'active')
            ->where('is_maintenance', false)
            ->when($this->option('tenant'), fn ($query, $ids) => $query->whereIn('id', $ids))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $service, &$unhealthy): void {
                $outcome = $service->checkAllEnabled();
                $unhealthy += $outcome['unhealthy'];
                $this->line("{$tenant->id}: {$outcome['checked']} installations checked, {$outcome['unhealthy']} unhealthy");
            });
        }

        return $unhealthy === 0 ? self::SUCCESS : self::FAILURE;
    }
}
