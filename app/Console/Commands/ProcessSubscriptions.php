<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Services\DunningService;

/**
 * Daily subscription processing of every tenant, on the subscription engine: renewal notices, renewals, expirations
 * and the other due transitions, then the billing of the due periods (unless SUBSCRIPTION_BILLING_SCHEDULED=false)
 * and the dunning of the overdue subscription invoices.
 */
class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';

    protected $description = 'Renew, invoice and dun the subscriptions of every tenant (subscription engine)';

    public function handle(TenantContext $tenantContext, RecurringBillingServiceInterface $billing, RenewalServiceInterface $renewals, DunningService $dunning): int
    {
        $invoiced = 0;
        $notified = 0;
        $escalated = 0;
        $billingEnabled = (bool) config('subscription_billing.scheduled_billing');

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $billing, $renewals, $dunning, $billingEnabled, &$invoiced, &$notified, &$escalated): void {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $billing, $renewals, $dunning, $billingEnabled, &$invoiced, &$notified, &$escalated): void {
                $notified += $renewals->processExpiringSubscriptions((string) $tenant->id)['notices_sent'];

                if ($billingEnabled) {
                    $invoiced += (int) $billing->executeBillingRun(new ExecuteBillingRunDTO(tenantId: (string) $tenant->id, companyId: (string) $tenant->id))->successful_invoices_count;
                }

                $escalated += $dunning->processDunning((string) $tenant->id)['escalations'];
            });
        });

        $this->info("Invoices generated: {$invoiced}; renewal notices: {$notified}; dunning escalations: {$escalated}");

        return self::SUCCESS;
    }
}
