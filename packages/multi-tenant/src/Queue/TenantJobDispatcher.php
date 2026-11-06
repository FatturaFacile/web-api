<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Queue;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Starts a scheduled job once per company of the installation, each in the context of its tenant. Companies being
 * restored from a backup are skipped.
 */
final class TenantJobDispatcher
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * @param  Closure(Tenant): object  $job  Builds the job of one tenant
     * @return int the number of tenants the job was dispatched for
     */
    public function dispatchForEachTenant(Closure $job): int
    {
        $tenants = Tenant::query()->where('status', 'active')->where('is_maintenance', false)->get();

        foreach ($tenants as $tenant) {
            // Not the dispatch() helper: it queues when its result is destroyed, after the tenant context has ended.
            $this->tenantContext->runWithTenant($tenant, fn () => $this->bus->dispatch($job($tenant)));
        }

        return $tenants->count();
    }
}
