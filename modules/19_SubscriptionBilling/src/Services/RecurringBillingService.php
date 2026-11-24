<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Events\InvoiceGeneratedEvent;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;
use Throwable;

/**
 * Recurring billing of the subscriptions, billed in advance, with metered usage billed in arrears.
 *
 * Each due period of a subscription is billed in its own transaction on the locked subscription row: the period is
 * claimed by an invoice schedule (unique per subscription and period start while processing or invoiced), the invoice
 * is created and issued by the invoicing service (gapless number, General Ledger posting), the revenue slices are
 * scheduled and the period advances. A failure rolls all of it back, the fiscal number included, and is logged as a
 * failed schedule that the next run retries. Running twice, or two runs at once, gives one invoice per period.
 */
final readonly class RecurringBillingService implements RecurringBillingServiceInterface
{
    /** Missed periods billed per subscription in one run (catch-up guard). */
    private const int MAX_PERIODS_PER_RUN = 12;

    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private PricingCalculator $pricing,
        private SubscriptionInvoiceIssuer $issuer,
        private RevenueRecognitionServiceInterface $revenue,
        private UsageBillingServiceInterface $usage,
        private RenewalService $renewals,
        private SubscriptionHistory $history,
    ) {}

    /**
     * Bills every period due up to the run date plus lookahead, for the tenant or one subscription.
     */
    public function executeBillingRun(ExecuteBillingRunDTO $dto): BillingRun
    {
        $runDate = $dto->runDate !== null ? CarbonImmutable::parse($dto->runDate)->startOfDay() : CarbonImmutable::today();
        $horizon = $runDate->addDays(max(0, $dto->lookaheadDays));

        $run = BillingRun::create([
            'tenant_id' => $dto->tenantId,
            'company_id' => $dto->companyId ?? $dto->tenantId,
            'run_number' => 'RUN-'.$runDate->format('Ymd').'-'.strtoupper(Str::random(8)),
            'period_start' => $runDate->toDateString(),
            'period_end' => $horizon->toDateString(),
            'execution_date' => CarbonImmutable::today()->toDateString(),
            'status' => 'processing',
            'executed_by' => $dto->executedBy !== null && Str::isUuid($dto->executedBy) ? $dto->executedBy : null,
        ]);

        $ids = Subscription::query()
            ->where('tenant_id', $dto->tenantId)
            ->when($dto->subscriptionId !== null, fn ($query) => $query->whereKey($dto->subscriptionId))
            ->where(fn ($query) => $query
                ->where(fn ($billable) => $billable->whereIn('status', Subscription::BILLABLE_STATUSES)->whereDate('current_period_start', '<=', $horizon->toDateString()))
                ->orWhere(fn ($paused) => $paused->where('status', 'paused')->whereNotNull('resume_on')->whereDate('resume_on', '<=', $horizon->toDateString())))
            ->orderBy('id')
            ->pluck('id');

        $invoiced = 0;
        $failed = 0;
        $total = '0';
        $errors = [];

        foreach ($ids as $id) {
            $outcome = $this->billSubscription((string) $id, $horizon, $runDate, $run, $dto->executedBy);
            $invoiced += count($outcome['schedules']);
            $total = Money::add($total, Money::sum(array_map(fn (InvoiceSchedule $schedule): string => (string) $schedule->getRawOriginal('estimated_amount'), $outcome['schedules'])));

            if ($outcome['error'] !== null) {
                $failed++;
                $errors[] = ['subscription_id' => (string) $id, 'error' => $outcome['error']];
            }
        }

        $run->update([
            'total_subscriptions_evaluated' => $ids->count(),
            'successful_invoices_count' => $invoiced,
            'failed_invoices_count' => $failed,
            'total_invoiced_amount' => Money::round2($total),
            'status' => $failed > 0 && $invoiced === 0 ? 'failed' : ($failed > 0 ? 'completed_with_errors' : 'completed'),
            'errors_log' => $errors !== [] ? $errors : null,
        ]);

        $this->auditLedger->record('BILLING_RUN_EXECUTED', $run, null, [
            'run_number' => $run->run_number,
            'evaluated' => $ids->count(),
            'invoiced' => $invoiced,
            'failed' => $failed,
            'total_amount' => Money::round2($total),
        ], 'MODULE_19_SUB');

        return $run->refresh();
    }

    /**
     * Bills the due periods of one subscription; stops at the first failure, which is logged on a failed schedule.
     *
     * @return array{schedules: list<InvoiceSchedule>, error: ?string}
     */
    public function billSubscription(string $subscriptionId, CarbonImmutable $horizon, ?CarbonImmutable $runDate = null, ?BillingRun $run = null, ?string $actorUserId = null): array
    {
        $runDate ??= CarbonImmutable::today();
        $schedules = [];

        for ($i = 0; $i < self::MAX_PERIODS_PER_RUN; $i++) {
            try {
                $schedule = DB::transaction(fn (): array => $this->billNextPeriod($subscriptionId, $horizon, $runDate, $run, $actorUserId));
            } catch (Throwable $e) {
                $this->recordFailure($subscriptionId, $run, $e);

                return ['schedules' => $schedules, 'error' => mb_substr($e->getMessage(), 0, 1000)];
            }

            if ($schedule['done']) {
                break;
            }

            if ($schedule['schedule'] !== null) {
                $schedules[] = $schedule['schedule'];
            }
        }

        return ['schedules' => $schedules, 'error' => null];
    }

    /**
     * Lines and amounts the next period of a subscription would be billed with, without billing it.
     *
     * @return array{period_start: string, period_end: string, lines: list<array<string, string>>, net_total: string}
     */
    public function preview(Subscription $subscription): array
    {
        $subscription->loadMissing(['plan.pricingTiers', 'plan.features', 'items.metric']);
        $start = $subscription->current_period_start ?? CarbonImmutable::today();
        [$periodStart, $periodEnd] = $this->periodOf($subscription, $start);
        $lines = $this->recurringLines($subscription, $periodStart, $periodEnd, $this->isFirstBilling($subscription));

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'lines' => array_map(fn (array $line): array => array_diff_key($line, ['method' => true, 'start' => true, 'end' => true]), $lines),
            'net_total' => Money::round2(Money::sum(array_column($lines, 'net'))),
        ];
    }

    /**
     * @return array{done: bool, schedule: ?InvoiceSchedule}
     */
    private function billNextPeriod(string $subscriptionId, CarbonImmutable $horizon, CarbonImmutable $runDate, ?BillingRun $run, ?string $actorUserId): array
    {
        $subscription = Subscription::query()->with(['plan.pricingTiers', 'plan.features', 'items.metric', 'customer'])->whereKey($subscriptionId)->lockForUpdate()->first();

        if ($subscription === null || $subscription->is_locked) {
            return ['done' => true, 'schedule' => null];
        }

        $this->renewals->applyDueTransitions($subscription, $horizon, $actorUserId);

        $start = $subscription->current_period_start;

        if (! in_array($subscription->status, Subscription::BILLABLE_STATUSES, true) || $start === null || $start->gt($horizon)) {
            return ['done' => true, 'schedule' => null];
        }

        if ($subscription->status === 'trialing') {
            // A trialing subscription whose first billing date is reached converts before it is billed.
            $this->renewals->convertTrial($subscription, $actorUserId);
        }

        [$periodStart, $periodEnd] = $this->periodOf($subscription, $start);
        $existing = InvoiceSchedule::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_kind', 'recurring')
            ->whereDate('service_period_start', $periodStart->toDateString())
            ->whereIn('status', ['processing', 'invoiced'])
            ->first();

        if ($existing === null) {
            $schedule = $this->billPeriod($subscription, $periodStart, $periodEnd, $runDate, $run, $actorUserId);
        } else {
            $schedule = null;
        }

        $this->advance($subscription, $periodStart);

        return ['done' => false, 'schedule' => $schedule];
    }

    private function billPeriod(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $runDate, ?BillingRun $run, ?string $actorUserId): ?InvoiceSchedule
    {
        $first = $this->isFirstBilling($subscription);
        $lines = $this->recurringLines($subscription, $periodStart, $periodEnd, $first);
        $usage = $this->usageLines($subscription, $periodStart);
        $lines = [...$lines, ...$usage['lines']];
        $net = Money::round2(Money::sum(array_column($lines, 'net')));
        $invoiceDate = $periodStart->gt($runDate) ? $runDate : $periodStart;

        $schedule = InvoiceSchedule::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'billing_kind' => 'recurring',
            'billing_run_id' => $run?->id,
            'scheduled_billing_date' => $invoiceDate->toDateString(),
            'service_period_start' => $periodStart->toDateString(),
            'service_period_end' => $periodEnd->toDateString(),
            'estimated_amount' => '0.00',
            'net_amount' => $net,
            'status' => 'processing',
        ]);

        if ($lines === [] || ! Money::isPositive($net)) {
            $schedule->update(['status' => 'skipped', 'error' => 'Nothing to invoice for the period.']);

            return null;
        }

        $invoice = $this->issuer->invoice(
            $subscription,
            array_map(fn (array $line): array => [
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'discount_percentage' => $line['discount_percentage'],
                'product_id' => $line['product_id'] ?? null,
            ], $lines),
            $invoiceDate,
            "Subscription {$subscription->subscription_number}: {$periodStart->toDateString()} - {$periodEnd->toDateString()}",
            $actorUserId
        );

        $schedule->update([
            'status' => 'invoiced',
            'invoice_id' => $invoice->id,
            'estimated_amount' => Money::round2((string) $invoice->total_gross),
            'net_amount' => Money::round2((string) $invoice->total_net),
            'error' => null,
        ]);

        if ($usage['aggregation_ids'] !== []) {
            UsageAggregation::query()->whereIn('id', $usage['aggregation_ids'])->update(['is_billed' => true, 'billed_at' => now(), 'invoice_schedule_id' => $schedule->id]);
        }

        $this->revenue->scheduleDocument($subscription, 'invoice', (string) $invoice->id, $schedule, array_map(fn (array $line): array => [
            'description' => $line['description'],
            'amount' => $line['net'],
            'method' => $line['method'],
            'start' => $line['start'],
            'end' => $line['end'],
        ], $lines));

        $subscription->forceFill(['last_billed_at' => now()])->save();

        $this->history->publish(new InvoiceGeneratedEvent(
            invoiceId: (string) $invoice->id,
            subscriptionId: (string) $subscription->id,
            amount: (float) Money::round2((string) $invoice->total_gross),
            currency: (string) $subscription->currency,
            servicePeriodStart: $periodStart->toDateString(),
            servicePeriodEnd: $periodEnd->toDateString(),
            tenantId: (string) $subscription->tenant_id,
            companyId: $this->history->companyOf($subscription)
        ));

        return $schedule->refresh();
    }

    /**
     * Invoice lines of the recurring items for a period, pro rata when the period is shorter than a full one, plus
     * the setup fee of the plan on the first billing.
     *
     * @return list<array{description: string, quantity: string, unit_price: string, tax_rate: string, discount_percentage: string, product_id?: ?string, net: string, method: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function recurringLines(Subscription $subscription, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, bool $first): array
    {
        $plan = $subscription->plan;
        $interval = (string) ($plan->billing_interval ?? 'monthly');
        $count = (int) ($plan->interval_count ?? 1);
        $fraction = BillingCalendar::fraction($subscription->anchor(), $periodStart, $periodEnd, $interval, $count, (string) $subscription->billing_alignment);
        $partial = Money::compare($fraction, '1') < 0;
        $suffix = $partial ? ' (pro rata '.BillingCalendar::days($periodStart, $periodEnd).' days)' : '';
        $lines = [];

        foreach ($subscription->items as $item) {
            /** @var SubscriptionItem $item */
            if ($item->item_type === 'usage_meter' || $item->metric_id !== null) {
                continue;
            }

            $amount = Money::round2(Money::mul($this->pricing->itemAmount($item, $plan), $fraction));

            if (! Money::isPositive($amount)) {
                continue;
            }

            [$quantity, $unitPrice] = $this->lineQuantityAndPrice($item, $amount);
            $lines[] = [
                'description' => mb_substr(($item->item_type === 'plan' && $plan !== null ? $plan->name : $item->description).$suffix, 0, 1000),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $this->issuer->taxRate($subscription, $item, $plan),
                'discount_percentage' => '0',
                'product_id' => $item->metadata['catalog_product_id'] ?? null,
                'net' => Money::round2(Money::mul($quantity, $unitPrice)),
                'method' => 'straight_line',
                'start' => $periodStart,
                'end' => $periodEnd,
            ];
        }

        $setupFee = Money::round2((string) ($plan?->getRawOriginal('setup_fee') ?? '0'));

        if ($first && $plan !== null && Money::isPositive($setupFee)) {
            $lines[] = [
                'description' => 'Setup fee: '.$plan->name,
                'quantity' => '1',
                'unit_price' => $setupFee,
                'tax_rate' => $this->issuer->taxRate($subscription, null, $plan),
                'discount_percentage' => '0',
                'net' => $setupFee,
                'method' => 'point_in_time',
                'start' => $periodStart,
                'end' => $periodStart,
            ];
        }

        return $lines;
    }

    /**
     * Usage of the metered items in the period before `$periodStart`, rated and billed in arrears.
     *
     * @return array{lines: list<array<string, mixed>>, aggregation_ids: list<string>}
     */
    private function usageLines(Subscription $subscription, CarbonImmutable $periodStart): array
    {
        $metered = $subscription->items->filter(fn (SubscriptionItem $item): bool => $item->item_type === 'usage_meter' || $item->metric_id !== null);

        if ($metered->isEmpty()) {
            return ['lines' => [], 'aggregation_ids' => []];
        }

        $previous = $this->previousPeriod($subscription, $periodStart);

        if ($previous !== null) {
            foreach ($metered as $item) {
                $this->usage->aggregateUsage((string) $item->id, $previous[0], $previous[1]);
            }
        }

        $aggregations = UsageAggregation::query()
            ->whereIn('subscription_item_id', $metered->pluck('id'))
            ->where('is_billed', false)
            ->whereDate('period_end', '<', $periodStart->toDateString())
            ->orderBy('period_start')
            ->get();

        $lines = [];
        $ids = [];

        foreach ($aggregations as $aggregation) {
            $ids[] = (string) $aggregation->id;
            $amount = Money::round2((string) $aggregation->getRawOriginal('billable_amount'));

            if (! Money::isPositive($amount)) {
                continue;
            }

            $item = $metered->firstWhere('id', $aggregation->subscription_item_id);
            $quantity = Money::round4((string) $aggregation->getRawOriginal('billable_overage'));
            $unitPrice = Money::round4((string) $aggregation->getRawOriginal('unit_price'));

            if (Money::compare(Money::round2(Money::mul($quantity, $unitPrice)), $amount) !== 0) {
                [$quantity, $unitPrice] = ['1', $amount];
            }

            $lines[] = [
                'description' => mb_substr(($item === null ? 'Usage' : $item->description).' ('.$aggregation->period_start->toDateString().' - '.$aggregation->period_end->toDateString().')', 0, 1000),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $this->issuer->taxRate($subscription, $item, $subscription->plan),
                'discount_percentage' => '0',
                'net' => Money::round2(Money::mul($quantity, $unitPrice)),
                'method' => 'point_in_time',
                'start' => CarbonImmutable::parse($aggregation->period_end),
                'end' => CarbonImmutable::parse($aggregation->period_end),
            ];
        }

        return ['lines' => $lines, 'aggregation_ids' => $ids];
    }

    /**
     * The billing period before the one starting on `$periodStart`, or null for the first one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function previousPeriod(Subscription $subscription, CarbonImmutable $periodStart): ?array
    {
        $last = InvoiceSchedule::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_kind', 'recurring')
            ->whereIn('status', ['invoiced', 'skipped'])
            ->whereDate('service_period_end', '<', $periodStart->toDateString())
            ->orderByDesc('service_period_start')
            ->first();

        return $last === null ? null : [CarbonImmutable::parse($last->service_period_start), CarbonImmutable::parse($last->service_period_end)];
    }

    /**
     * Quantity and unit price of an invoice line that add up exactly to the amount of the item for the period.
     *
     * @return array{0: string, 1: string}
     */
    private function lineQuantityAndPrice(SubscriptionItem $item, string $amount): array
    {
        $quantity = Money::round4((string) $item->getRawOriginal('quantity'));

        if (Money::isPositive($quantity) && Money::compare($quantity, '1') !== 0) {
            $unitPrice = Money::round4(Money::div($amount, $quantity));

            if (Money::compare(Money::round2(Money::mul($quantity, $unitPrice)), $amount) === 0) {
                return [$quantity, $unitPrice];
            }
        }

        return ['1', $amount];
    }

    /**
     * Start and end of the period of a subscription starting on a date, cut at the cancellation date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodOf(Subscription $subscription, CarbonInterface $start): array
    {
        $plan = $subscription->plan;
        [$periodStart, $periodEnd] = BillingCalendar::period(
            $subscription->anchor(),
            $start,
            (string) ($plan->billing_interval ?? 'monthly'),
            (int) ($plan->interval_count ?? 1),
            (string) $subscription->billing_alignment
        );

        $effective = $subscription->cancellation_effective_date;

        if ($effective !== null && $effective->gt($periodStart) && $effective->lte($periodEnd)) {
            $periodEnd = $effective->subDay();
        }

        return [$periodStart, $periodEnd];
    }

    private function advance(Subscription $subscription, CarbonImmutable $periodStart): void
    {
        $plan = $subscription->plan;
        $interval = (string) ($plan->billing_interval ?? 'monthly');
        $count = (int) ($plan->interval_count ?? 1);
        $next = BillingCalendar::nextPeriodStart($subscription->anchor(), $periodStart, $interval, $count, (string) $subscription->billing_alignment);
        [, $nextEnd] = BillingCalendar::period($subscription->anchor(), $next, $interval, $count, (string) $subscription->billing_alignment);

        $subscription->forceFill([
            'current_period_start' => $next->toDateString(),
            'current_period_end' => $nextEnd->toDateString(),
        ])->save();
    }

    private function isFirstBilling(Subscription $subscription): bool
    {
        return ! InvoiceSchedule::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_kind', 'recurring')
            ->where('status', 'invoiced')
            ->exists();
    }

    /**
     * Logs a failed attempt outside the rolled back transaction, so the next run sees and retries it.
     */
    private function recordFailure(string $subscriptionId, ?BillingRun $run, Throwable $e): void
    {
        $subscription = Subscription::query()->find($subscriptionId);

        if ($subscription === null || $subscription->current_period_start === null) {
            return;
        }

        InvoiceSchedule::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'billing_kind' => 'recurring',
            'billing_run_id' => $run?->id,
            'scheduled_billing_date' => CarbonImmutable::today()->toDateString(),
            'service_period_start' => $subscription->current_period_start->toDateString(),
            'service_period_end' => ($subscription->current_period_end ?? $subscription->current_period_start)->toDateString(),
            'estimated_amount' => '0.00',
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        report($e);
    }
}
