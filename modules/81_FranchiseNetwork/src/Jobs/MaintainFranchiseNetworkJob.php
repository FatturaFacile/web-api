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
use Modules\FranchiseNetwork\Services\AgreementService;
use Modules\FranchiseNetwork\Services\CatalogPushService;

/**
 * Daily upkeep of one franchisor: agreements past their end date are renewed or expired, the renewal reminders go out once, the
 * deferred entry fee shares of the month are recognised and the failed pushes whose retry is due are delivered again.
 */
final class MaintainFranchiseNetworkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId) {}

    public function handle(AgreementService $agreements, CatalogPushService $pushes, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $context->runWithTenant($tenant, function () use ($agreements, $pushes): void {
            if (! FranchiseStore::query()->exists()) {
                return;
            }

            $agreements->maintain();
            $agreements->recognizeDeferrals(now()->format('Y-m'));
            $pushes->retryDue();
        });
    }
}
