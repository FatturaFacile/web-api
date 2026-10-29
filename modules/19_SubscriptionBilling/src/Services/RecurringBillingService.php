<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Events\DeferredRevenueReadyEvent;
use Modules\SubscriptionBilling\Events\InvoiceGeneratedEvent;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\UsageAggregation;

final readonly class RecurringBillingService implements RecurringBillingServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function executeBillingRun(ExecuteBillingRunDTO $dto): BillingRun
    {
        return DB::transaction(function () use ($dto) {
            $runDate = $dto->runDate ? Carbon::parse($dto->runDate) : now();
            $periodEnd = $runDate->copy()->addDays($dto->lookaheadDays);
            $runNumber = 'RUN-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $billingRun = BillingRun::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'run_number' => $runNumber,
                'period_start' => $runDate->copy()->startOfMonth()->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'execution_date' => $runDate->toDateString(),
                'status' => 'processing',
                'executed_by' => $dto->executedBy,
            ]);

            $subscriptions = Subscription::with(['items', 'plan'])
                ->where('tenant_id', $dto->tenantId)
                ->where('status', 'active')
                ->where('current_period_end', '<=', $periodEnd->toDateString())
                ->lockForUpdate()
                ->get();

            $totalEvaluated = $subscriptions->count();
            $successCount = 0;
            $failedCount = 0;
            $totalInvoicedAmount = 0.00;
            $errors = [];

            foreach ($subscriptions as $subscription) {
                try {
                    $periodStart = Carbon::parse($subscription->current_period_start);
                    $periodEndSub = Carbon::parse($subscription->current_period_end);

                    $invoiceData = $this->generateRecurringInvoice($subscription, $periodStart, $periodEndSub);
                    $totalInvoicedAmount += $invoiceData['total_amount'];
                    $successCount++;

                    // Advance cycle period
                    $interval = $subscription->plan?->billing_interval ?? 'monthly';
                    $newPeriodStart = $periodEndSub->copy()->addDay();
                    $newPeriodEnd = match ($interval) {
                        'annual' => $newPeriodStart->copy()->addYear()->subDay(),
                        'quarterly' => $newPeriodStart->copy()->addMonths(3)->subDay(),
                        'semiannual' => $newPeriodStart->copy()->addMonths(6)->subDay(),
                        default => $newPeriodStart->copy()->addMonth()->subDay(),
                    };

                    $subscription->update([
                        'current_period_start' => $newPeriodStart->toDateString(),
                        'current_period_end' => $newPeriodEnd->toDateString(),
                    ]);
                } catch (\Throwable $e) {
                    $failedCount++;
                    $errors[] = [
                        'subscription_id' => $subscription->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $billingRun->update([
                'total_subscriptions_evaluated' => $totalEvaluated,
                'successful_invoices_count' => $successCount,
                'failed_invoices_count' => $failedCount,
                'total_invoiced_amount' => $totalInvoicedAmount,
                'status' => $failedCount > 0 && $successCount === 0 ? 'failed' : 'completed',
                'errors_log' => ! empty($errors) ? $errors : null,
            ]);

            $this->auditLedger->record(
                'BILLING_RUN_EXECUTED',
                $billingRun,
                null,
                [
                    'run_number' => $runNumber,
                    'evaluated' => $totalEvaluated,
                    'successful' => $successCount,
                    'total_amount' => $totalInvoicedAmount,
                ],
                'MODULE_19_SUB'
            );

            return $billingRun;
        });
    }

    public function generateRecurringInvoice(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): array
    {
        $items = $subscription->items;
        $recurringTotal = 0.00;
        $lines = [];

        foreach ($items as $item) {
            $lineAmount = (float) $item->subtotal_recurring;
            $recurringTotal += $lineAmount;
            $lines[] = [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => $lineAmount,
                'type' => $item->item_type,
            ];
        }

        // Check for unbilled usage overages in this cycle
        $usageAggregations = UsageAggregation::whereIn('subscription_item_id', $items->pluck('id'))
            ->where('is_billed', false)
            ->where('period_end', '<=', $periodEnd->toDateString())
            ->get();

        $usageTotal = 0.00;
        foreach ($usageAggregations as $agg) {
            $billableAmount = (float) $agg->billable_amount;
            if ($billableAmount > 0) {
                $usageTotal += $billableAmount;
                $lines[] = [
                    'description' => 'Metered Usage Overage ('.$agg->period_start->toDateString().' - '.$agg->period_end->toDateString().')',
                    'quantity' => (float) $agg->billable_overage,
                    'unit_price' => (float) $agg->unit_price,
                    'total' => $billableAmount,
                    'type' => 'usage_overage',
                ];
                $agg->update([
                    'is_billed' => true,
                    'billed_at' => now(),
                ]);
            }
        }

        $totalAmount = round($recurringTotal + $usageTotal, 2);
        $syntheticInvoiceId = (string) Str::uuid7();

        $schedule = InvoiceSchedule::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'scheduled_billing_date' => now()->toDateString(),
            'service_period_start' => $periodStart->toDateString(),
            'service_period_end' => $periodEnd->toDateString(),
            'estimated_amount' => $totalAmount,
            'status' => 'invoiced',
            'invoice_id' => $syntheticInvoiceId,
        ]);

        $this->dispatcher->dispatch(new InvoiceGeneratedEvent(
            invoiceId: $syntheticInvoiceId,
            subscriptionId: $subscription->id,
            amount: $totalAmount,
            currency: $subscription->currency,
            servicePeriodStart: $periodStart->toDateString(),
            servicePeriodEnd: $periodEnd->toDateString(),
            tenantId: $subscription->tenant_id,
            companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
        ));

        // Deferred revenue trigger: Dr Accounts Receivable / Cr Deferred Revenue
        $this->dispatcher->dispatch(new DeferredRevenueReadyEvent(
            invoiceId: $syntheticInvoiceId,
            subscriptionId: $subscription->id,
            deferredAmount: $totalAmount,
            currency: $subscription->currency,
            debitAccount: '1200_ACCOUNTS_RECEIVABLE',
            creditAccount: '2400_DEFERRED_REVENUE',
            tenantId: $subscription->tenant_id,
            companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
        ));

        return [
            'invoice_id' => $syntheticInvoiceId,
            'schedule_id' => $schedule->id,
            'lines' => $lines,
            'recurring_total' => $recurringTotal,
            'usage_total' => $usageTotal,
            'total_amount' => $totalAmount,
        ];
    }
}
