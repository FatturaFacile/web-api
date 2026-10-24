<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Subscriptions\NotifyUpcomingRenewals;
use App\Actions\Subscriptions\RunSubscriptionBilling;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Invoice all due subscriptions and log upcoming renewal notices for every tenant';

    public function handle(TenantContext $tenantContext, RunSubscriptionBilling $billing, NotifyUpcomingRenewals $renewals): int
    {
        $invoiced = 0;
        $notified = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $billing, $renewals, &$invoiced, &$notified): void {
            $tenantContext->runWithTenant($tenant, function () use ($billing, $renewals, &$invoiced, &$notified): void {
                Subscription::query()
                    ->where('status', 'active')
                    ->whereDate('next_billing_date', '<=', now())
                    ->get()
                    ->each(function (Subscription $subscription) use ($billing, &$invoiced): void {
                        $invoiced += count($billing->execute($subscription));
                    });

                $notified += $renewals->execute();
            });
        });

        $this->info("Invoices generated: {$invoiced}; renewal notices: {$notified}");

        return self::SUCCESS;
    }
}
