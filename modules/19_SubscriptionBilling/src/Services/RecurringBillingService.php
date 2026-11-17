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
use Modules\SubscriptionBilling\Support\BillingCycle;

final readonly class RecurringBillingService implements RecurringBillingServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private SubscriptionInvoiceIssuer $invoiceIssuer
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

            $horizon = $periodEnd->toDateString();
            $subscriptions = Subscription::with(['items', 'plan'])
                ->where('tenant_id', $dto->tenantId)
                ->where('status', 'active')
                ->where(function ($query) use ($horizon): void {
                    // Arrears (posticipata): the period is billed once it has ended.
                    $query->where(function ($q) use ($horizon): void {
                        $q->where('billing_timing', '!=', 'advance')
                            ->whereDate('current_period_end', '<=', $horizon);
                    })
                    // Advance (anticipata): the period is billed before it starts, then rolled forward.
                        ->orWhere(function ($q) use ($horizon): void {
                            $q->where('billing_timing', 'advance')
                                ->where(function ($inner) use ($horizon): void {
                                    $inner->whereDate('current_period_end', '<=', $horizon)
                                        ->orWhere(function ($unbilled) use ($horizon): void {
                                            $unbilled->whereDate('current_period_start', '<=', $horizon)
                                                ->where(function ($w): void {
                                                    $w->whereNull('last_billed_period_end')
                                                        ->orWhereColumn('last_billed_period_end', '<', 'current_period_end');
                                                });
                                        });
                                });
                        });
                })
                ->lockForUpdate()
                ->get();

            $totalEvaluated = $subscriptions->count();
            $successCount = 0;
            $failedCount = 0;
            $totalInvoicedAmount = 0.00;
            $errors = [];

            foreach ($subscriptions as $subscription) {
                try {
                    $invoiceData = $this->billSubscriptionCycle($subscription);
                    $totalInvoicedAmount += $invoiceData['total_amount'];
                    $successCount++;
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

    /**
     * Bills the current cycle of one subscription honouring timing (advance/arrears) and rolls the period.
     *
     * @return array{invoice_id: string, schedule_id: string|null, lines: array, recurring_total: float, usage_total: float, total_amount: float}
     */
    private function billSubscriptionCycle(Subscription $subscription): array
    {
        $interval = $subscription->plan?->billing_interval ?? 'monthly';
        $periodStart = Carbon::parse($subscription->current_period_start);
        $periodEnd = Carbon::parse($subscription->current_period_end);

        if ($subscription->billing_timing === 'advance') {
            $billedUntil = $subscription->last_billed_period_end ? Carbon::parse($subscription->last_billed_period_end) : null;

            if ($billedUntil === null || $billedUntil->lt($periodEnd)) {
                $data = $this->generateRecurringInvoice($subscription, $periodStart, $periodEnd);
                $subscription->update(['last_billed_period_end' => $periodEnd->toDateString()]);

                return $data;
            }

            $periodStart = $periodEnd->copy()->addDay();
            $periodEnd = Carbon::instance(BillingCycle::periodEnd($periodStart, $interval));
            $subscription->update([
                'current_period_start' => $periodStart->toDateString(),
                'current_period_end' => $periodEnd->toDateString(),
            ]);
            $data = $this->generateRecurringInvoice($subscription, $periodStart, $periodEnd);
            $subscription->update(['last_billed_period_end' => $periodEnd->toDateString()]);

            return $data;
        }

        $data = $this->generateRecurringInvoice($subscription, $periodStart, $periodEnd);

        $newPeriodStart = $periodEnd->copy()->addDay();
        $newPeriodEnd = Carbon::instance(BillingCycle::periodEnd($newPeriodStart, $interval));
        $subscription->update([
            'current_period_start' => $newPeriodStart->toDateString(),
            'current_period_end' => $newPeriodEnd->toDateString(),
            'last_billed_period_end' => $periodEnd->toDateString(),
        ]);

        return $data;
    }

    /**
     * Final settlement when a contract ends mid-period.
     * - Unbilled service (arrears, or advance never billed): final pro-rata invoice up to the end date.
     * - Advance already billed: pro-rata credit for the unused days (schedule row 'credit_pending').
     *
     * @return array{type: string, amount: float, factor: float, service_days: int, cycle_days: int, invoice_id: string|null}|null
     */
    public function billFinalProRata(Subscription $subscription, Carbon $endDate): ?array
    {
        $periodStart = Carbon::parse($subscription->current_period_start);
        $periodEnd = Carbon::parse($subscription->current_period_end);
        $endDate = $endDate->copy()->startOfDay();
        $billedUntil = $subscription->last_billed_period_end ? Carbon::parse($subscription->last_billed_period_end) : null;

        if ($endDate->lt($periodStart)) {
            return null;
        }

        $alreadyBilled = $billedUntil !== null && $billedUntil->gte($periodEnd);

        if ($alreadyBilled) {
            if ($endDate->gte($periodEnd)) {
                return null;
            }

            $unusedFrom = $endDate->copy()->addDay();
            $calc = BillingCycle::prorate($this->recurringTotal($subscription), $unusedFrom, $periodEnd, $periodStart, $periodEnd);
            $credit = -$calc['amount'];

            InvoiceSchedule::create([
                'tenant_id' => $subscription->tenant_id,
                'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id,
                'scheduled_billing_date' => now()->toDateString(),
                'service_period_start' => $unusedFrom->toDateString(),
                'service_period_end' => $periodEnd->toDateString(),
                'estimated_amount' => $credit,
                'status' => 'credit_pending',
                'proration_factor' => $calc['factor'],
                'service_days' => $calc['service_days'],
                'cycle_days' => $calc['cycle_days'],
                'invoice_type' => 'final_prorata',
                'calculation' => ['kind' => 'unused_days_credit', 'cancellation_date' => $endDate->toDateString()],
            ]);

            return ['type' => 'credit', 'amount' => $credit, 'factor' => $calc['factor'], 'service_days' => $calc['service_days'], 'cycle_days' => $calc['cycle_days'], 'invoice_id' => null];
        }

        $serviceStart = $billedUntil !== null && $billedUntil->gte($periodStart) ? $billedUntil->copy()->addDay() : $periodStart->copy();
        $serviceEnd = $endDate->lt($periodEnd) ? $endDate : $periodEnd;

        if ($serviceEnd->lt($serviceStart)) {
            return null;
        }

        $calc = BillingCycle::prorate(1.0, $serviceStart, $serviceEnd, $periodStart, $periodEnd);
        $data = $this->generateRecurringInvoice($subscription, $serviceStart, $serviceEnd, [
            'factor' => $calc['factor'],
            'service_days' => $calc['service_days'],
            'cycle_days' => $calc['cycle_days'],
        ], 'final_prorata');

        $subscription->update(['last_billed_period_end' => $serviceEnd->toDateString()]);

        return ['type' => 'invoice', 'amount' => $data['total_amount'], 'factor' => $calc['factor'], 'service_days' => $calc['service_days'], 'cycle_days' => $calc['cycle_days'], 'invoice_id' => $data['invoice_id']];
    }

    /**
     * @param  array{factor: float, service_days: int, cycle_days: int}|null  $proration  explicit pro-rata, otherwise derived (calendar-aligned first period)
     * @param  string  $invoiceType  regular|final_prorata
     */
    public function generateRecurringInvoice(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd, ?array $proration = null, string $invoiceType = 'regular'): array
    {
        $items = $subscription->items;
        $recurringTotal = 0.00;
        $lines = [];

        $proration ??= $this->derivedProration($subscription, $periodStart, $periodEnd);
        $factor = (float) $proration['factor'];
        $isProrated = $factor < 1.0;
        $suffix = $isProrated ? sprintf(' (pro-rata %d/%d gg)', $proration['service_days'], $proration['cycle_days']) : '';

        foreach ($items as $item) {
            $lineAmount = round((float) $item->subtotal_recurring * $factor, 2);
            $recurringTotal += $lineAmount;
            $lines[] = [
                'description' => $item->description.$suffix,
                'quantity' => (float) $item->quantity,
                'unit_price' => $isProrated ? round((float) $item->unit_price * $factor, 4) : (float) $item->unit_price,
                'total' => $lineAmount,
                'type' => $item->item_type,
            ];
        }

        // Check for unbilled usage overages in this cycle
        $usageAggregations = UsageAggregation::whereIn('subscription_item_id', $items->pluck('id'))
            ->where('is_billed', false)
            ->whereDate('period_end', '<=', $periodEnd->toDateString())
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

        $invoice = $this->invoiceIssuer->issue(
            $subscription,
            array_map(fn (array $l): array => [
                'description' => $l['description'],
                'quantity' => 1,
                'unit_price' => $l['total'],
            ], array_values(array_filter($lines, fn (array $l): bool => $l['total'] != 0))),
            $periodStart,
            $periodEnd
        );
        $invoiceId = $invoice?->id ?? (string) Str::uuid7();

        $schedule = InvoiceSchedule::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'scheduled_billing_date' => now()->toDateString(),
            'service_period_start' => $periodStart->toDateString(),
            'service_period_end' => $periodEnd->toDateString(),
            'estimated_amount' => $totalAmount,
            'status' => 'invoiced',
            'invoice_id' => $invoiceId,
            'proration_factor' => $factor,
            'service_days' => $proration['service_days'],
            'cycle_days' => $proration['cycle_days'],
            'invoice_type' => $invoiceType === 'regular' && $isProrated ? 'initial_prorata' : $invoiceType,
            'calculation' => ['lines' => $lines, 'recurring_total' => round($recurringTotal, 2), 'usage_total' => round($usageTotal, 2)],
        ]);

        $this->dispatcher->dispatch(new InvoiceGeneratedEvent(
            invoiceId: $invoiceId,
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
            invoiceId: $invoiceId,
            subscriptionId: $subscription->id,
            deferredAmount: $totalAmount,
            currency: $subscription->currency,
            debitAccount: '1200_ACCOUNTS_RECEIVABLE',
            creditAccount: '2400_DEFERRED_REVENUE',
            tenantId: $subscription->tenant_id,
            companyId: (string) ($subscription->company_id ?? $subscription->tenant_id)
        ));

        return [
            'invoice_id' => $invoiceId,
            'schedule_id' => $schedule->id,
            'lines' => $lines,
            'recurring_total' => $recurringTotal,
            'usage_total' => $usageTotal,
            'total_amount' => $totalAmount,
            'proration_factor' => $factor,
        ];
    }

    /**
     * First period of a calendar-aligned subscription: pro-rata over the full calendar cycle.
     *
     * @return array{factor: float, service_days: int, cycle_days: int}
     */
    private function derivedProration(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): array
    {
        $days = BillingCycle::daysInclusive($periodStart, $periodEnd);
        $full = ['factor' => 1.0, 'service_days' => $days, 'cycle_days' => $days];

        if ($subscription->billing_alignment !== BillingCycle::CALENDAR
            || ! $subscription->start_date
            || ! $periodStart->isSameDay($subscription->start_date)) {
            return $full;
        }

        [$bucketStart, $bucketEnd] = BillingCycle::calendarBucket($periodStart, $subscription->plan?->billing_interval ?? 'monthly');
        $calc = BillingCycle::prorate(1.0, $periodStart, $periodEnd, $bucketStart, $bucketEnd);

        return ['factor' => $calc['factor'], 'service_days' => $calc['service_days'], 'cycle_days' => $calc['cycle_days']];
    }

    private function recurringTotal(Subscription $subscription): float
    {
        return (float) $subscription->items
            ->where('item_type', '!=', 'usage_meter')
            ->sum(fn ($item): float => (float) $item->subtotal_recurring);
    }
}
