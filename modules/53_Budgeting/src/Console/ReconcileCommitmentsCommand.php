<?php

declare(strict_types=1);

namespace Modules\Budgeting\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\Budgeting\Integration\ProcurementCommitmentSync;

/**
 * Recomputes the commitments of requisitions and purchase orders from purchasing, tenant by tenant: repairs drift when
 * an event was missed (a document closed or cancelled outside the normal flow).
 */
final class ReconcileCommitmentsCommand extends Command
{
    protected $signature = 'budgeting:reconcile-commitments {--tenant= : Only this tenant id}';

    protected $description = 'Recompute the budget commitments of requisitions and purchase orders';

    public function handle(ProcurementCommitmentSync $sync, TenantContext $context): int
    {
        $only = $this->option('tenant');
        $documents = 0;

        Tenant::query()
            ->when(is_string($only) && $only !== '', static fn ($query) => $query->whereKey($only))
            ->each(function (Tenant $tenant) use ($sync, $context, &$documents): void {
                $context->runWithTenant($tenant, function () use ($sync, $tenant, &$documents): void {
                    $documents += $sync->syncAll((string) $tenant->id);
                });
            });

        $this->info(sprintf('Documents reconciled: %d', $documents));

        return self::SUCCESS;
    }
}
