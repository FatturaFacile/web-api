<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Jobs;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Services\BenchmarkService;

/**
 * Monthly KPI snapshot of the stores of one franchisor and the anonymous statistics of the network and of each size cluster.
 */
final class SnapshotBenchmarksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId, public readonly ?string $period = null) {}

    public function handle(BenchmarkService $benchmark, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $context->runWithTenant($tenant, function () use ($benchmark): void {
            if (FranchiseStore::query()->exists()) {
                $benchmark->snapshot($this->period ?? now()->subMonthNoOverflow()->format('Y-m'));
            }
        });
    }
}
