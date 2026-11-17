<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Services\IndexationService;

/**
 * Nightly engine: for every tenant applies due inflation indexations, bills matured cycles
 * (pro-rata included) and renews expiring contracts - no human intervention.
 */
final class RunSubscriptionAutomationCommand extends Command
{
    protected $signature = 'subscriptions:run-automation {--date= : Run date (Y-m-d), defaults to today} {--lookahead=0 : Days of billing lookahead} {--renewal-lookahead=30 : Days of renewal lookahead}';

    protected $description = 'Nightly subscription automation: indexation, recurring invoicing (pro-rata) and renewals for every tenant';

    public function handle(
        TenantContext $tenantContext,
        RecurringBillingServiceInterface $billing,
        RenewalServiceInterface $renewals,
        IndexationService $indexation,
    ): int {
        $runDate = $this->option('date') ?: now()->toDateString();
        $totals = ['indexed' => 0, 'invoices' => 0, 'amount' => 0.0, 'renewed' => 0, 'failed' => 0];

        Tenant::query()->each(function (Tenant $tenant) use ($tenantContext, $billing, $renewals, $indexation, $runDate, &$totals): void {
            $tenantContext->runWithTenant($tenant, function () use ($tenant, $billing, $renewals, $indexation, $runDate, &$totals): void {
                try {
                    // Indexation first, so the new fee applies to the cycle invoiced tonight.
                    $totals['indexed'] += $indexation->processDueIndexations((string) $tenant->id, Carbon::parse($runDate))['applied'];

                    $run = $billing->executeBillingRun(new ExecuteBillingRunDTO(
                        tenantId: (string) $tenant->id,
                        companyId: (string) $tenant->id,
                        runDate: $runDate,
                        lookaheadDays: (int) $this->option('lookahead'),
                        executedBy: null,
                    ));
                    $totals['invoices'] += (int) $run->successful_invoices_count;
                    $totals['failed'] += (int) $run->failed_invoices_count;
                    $totals['amount'] += (float) $run->total_invoiced_amount;

                    $totals['renewed'] += $renewals->processExpiringSubscriptions((string) $tenant->id, (int) $this->option('renewal-lookahead'))['auto_renewed_count'];
                } catch (\Throwable $e) {
                    $totals['failed']++;
                    $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
                }
            });
        });

        $this->info(sprintf('Indexed: %d; invoices: %d (%.2f); renewed: %d; failures: %d', $totals['indexed'], $totals['invoices'], $totals['amount'], $totals['renewed'], $totals['failed']));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
