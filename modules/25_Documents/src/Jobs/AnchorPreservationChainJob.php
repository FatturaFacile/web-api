<?php

declare(strict_types=1);

namespace Modules\Documents\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Documents\Services\PreservationChainService;

/**
 * Queued job: anchor the closed preservation packages of a company that are not chained yet.
 */
final class AnchorPreservationChainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Inject the tenant.
     */
    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Run the unit of work.
     *
     * @return int number of links appended
     */
    public function handle(TenantContext $context, PreservationChainService $chain): int
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return 0;
        }

        $context->setTenant($tenant);

        try {
            return count($chain->anchorClosedPackages());
        } finally {
            $context->clear();
        }
    }
}
