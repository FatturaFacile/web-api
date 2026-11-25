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
use Modules\FranchiseNetwork\Domain\Enums\RunStatus;
use Modules\FranchiseNetwork\Models\FranchiseStore;
use Modules\FranchiseNetwork\Models\RoyaltyRun;
use Modules\FranchiseNetwork\Services\RoyaltyBillingService;

/**
 * Monthly billing of one franchisor: calculates the royalty and marketing fund run of the month that just ended (or the given
 * period) and, when asked or when the company enabled automatic invoicing, issues the invoices. A run already invoiced is left alone.
 */
final class CalculateRoyaltiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $tenantId, public readonly ?string $period = null, public readonly bool $invoice = false) {}

    public function handle(RoyaltyBillingService $billing, TenantContext $context): void
    {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $context->runWithTenant($tenant, function () use ($billing): void {
            if (! FranchiseStore::query()->exists()) {
                return;
            }

            $period = $this->period ?? now()->subMonthNoOverflow()->format('Y-m');

            if (RoyaltyRun::query()->where('period', $period)->where('status', RunStatus::Invoiced->value)->exists()) {
                return;
            }

            $run = $billing->calculate($period, null);

            if ($this->invoice || (bool) config('franchise_network.billing.auto_invoice')) {
                $billing->invoice($run, null);
            }
        });
    }
}
