<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Models\Contact;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;

/**
 * Keeps the deprecated /api/v1/subscriptions contract working on the subscription engine: the legacy payloads are
 * translated to module operations (carrier plan per interval, the charges as items, the end date as the term) and the
 * module subscriptions are returned in the legacy shape.
 */
final readonly class LegacySubscriptionBridge
{
    public function __construct(
        private TenantContext $tenantContext,
        private LegacyPlanCatalog $plans,
        private SubscriptionLifecycleServiceInterface $lifecycle,
        private RecurringBillingServiceInterface $billing,
        private SubscriptionHistory $history,
        private PricingCalculator $pricing,
        private DocumentEventRecorder $documentEvents,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function paginate(?string $status, int $perPage): LengthAwarePaginator
    {
        return Subscription::query()
            ->with(['customer', 'plan'])
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->latest('start_date')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated legacy payload
     */
    public function create(array $data, ?string $actorUserId): Subscription
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $plan = $this->plans->plan($tenantId, (string) $data['interval'], (int) ($data['interval_count'] ?? 1), (string) $data['currency']);
        $paymentTerms = Contact::query()->whereKey($data['contact_id'])->value('payment_terms_days');

        return $this->lifecycle->createSubscription(new CreateSubscriptionDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            customerId: (string) $data['contact_id'],
            planId: (string) $plan->id,
            startDate: (string) $data['start_date'],
            contractTermMonths: BillingCalendar::monthsPerPeriod((string) $plan->billing_interval, (int) $plan->interval_count),
            autoRenew: (bool) ($data['auto_renew'] ?? false),
            billingDayOfMonth: (int) CarbonImmutable::parse((string) $data['start_date'])->day,
            paymentTermsDays: (int) ($paymentTerms ?? 30),
            paymentMethod: 'bank_transfer',
            items: $this->items($data['items']),
            metadata: ['source' => 'legacy_api'],
            name: (string) $data['name'],
            trialDays: 0,
            billingAlignment: BillingCalendar::ANNIVERSARY,
            renewalNoticeDays: (int) ($data['notify_days_before'] ?? 30),
            cancellationNoticeDays: 0,
            termEndDate: $data['end_date'] ?? null,
            includePlanItem: false,
            notes: $data['notes'] ?? null,
            currency: strtoupper((string) $data['currency']),
        ), $actorUserId);
    }

    /**
     * Updates the header and, when given, replaces the charges (future periods only; the replaced items are kept as
     * soft-deleted history).
     *
     * @param  array<string, mixed>  $data  validated legacy payload
     */
    public function update(string $id, array $data, ?string $actorUserId): Subscription
    {
        return DB::transaction(function () use ($id, $data, $actorUserId): Subscription {
            $subscription = Subscription::query()->with(['plan', 'items'])->whereKey($id)->lockForUpdate()->firstOrFail();
            $billed = InvoiceSchedule::query()->where('subscription_id', $subscription->id)->where('status', 'invoiced')->exists();
            $changes = [];

            foreach (['name' => 'name', 'notes' => 'notes', 'auto_renew' => 'auto_renew', 'notify_days_before' => 'renewal_notice_days', 'end_date' => 'term_end_date', 'contact_id' => 'customer_id'] as $legacy => $column) {
                if (array_key_exists($legacy, $data)) {
                    $changes[$column] = $data[$legacy];
                }
            }

            if (isset($data['start_date']) && CarbonImmutable::parse((string) $data['start_date'])->toDateString() !== $subscription->start_date?->toDateString()) {
                if ($billed) {
                    throw ValidationException::withMessages(['start_date' => __('The start date of a subscription already billed cannot change.')]);
                }

                $start = CarbonImmutable::parse((string) $data['start_date']);
                [, $end] = BillingCalendar::period($start, $start, (string) $subscription->plan->billing_interval, (int) $subscription->plan->interval_count, BillingCalendar::ANNIVERSARY);
                $changes += ['start_date' => $start->toDateString(), 'billing_anchor_date' => $start->toDateString(), 'current_period_start' => $start->toDateString(), 'current_period_end' => $end->toDateString()];
            }

            if (isset($data['interval']) || isset($data['interval_count']) || isset($data['currency'])) {
                $plan = $this->plans->plan((string) $subscription->tenant_id, (string) ($data['interval'] ?? $this->plans->legacyInterval($subscription->plan->billing_interval)), (int) ($data['interval_count'] ?? $subscription->plan->interval_count), (string) ($data['currency'] ?? $subscription->currency));

                if ($plan->id !== $subscription->plan_id) {
                    $anchor = $subscription->current_period_start ?? CarbonImmutable::today();
                    [, $end] = BillingCalendar::period($anchor, $anchor, (string) $plan->billing_interval, (int) $plan->interval_count, BillingCalendar::ANNIVERSARY);
                    $changes += ['plan_id' => $plan->id, 'currency' => $plan->currency, 'billing_anchor_date' => $anchor->toDateString(), 'current_period_end' => $end->toDateString(), 'contract_term_months' => BillingCalendar::monthsPerPeriod((string) $plan->billing_interval, (int) $plan->interval_count)];
                }
            }

            $subscription->forceFill($changes)->save();
            $subscription->load('plan');

            if (isset($data['items'])) {
                $subscription->items()->get()->each(fn (SubscriptionItem $item) => $item->delete());

                foreach ($this->items($data['items']) as $line) {
                    $item = SubscriptionItem::create([
                        'tenant_id' => $subscription->tenant_id,
                        'subscription_id' => $subscription->id,
                        'item_type' => 'charge',
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'discount_percentage' => $line['discount_percentage'],
                        'tax_rate' => $line['tax_rate'],
                        'price_factor' => '1',
                        'metadata' => array_filter(['catalog_product_id' => $line['catalog_product_id']]),
                    ]);
                    $item->update(['subtotal_recurring' => $this->pricing->itemAmount($item, $subscription->plan)]);
                }

                $subscription->unsetRelation('items');
            }

            [$before, $after] = $this->history->refreshMrr($subscription, $subscription->status === 'active');
            $this->history->version($subscription, 'amendment', $before, $after, CarbonImmutable::today(), 'Updated through the recurring billing API', $actorUserId);
            $this->documentEvents->record($subscription, 'updated');

            return $subscription->refresh();
        });
    }

    /**
     * Pause, resume or cancel through the lifecycle of the engine (a legacy cancellation never refunds).
     */
    public function transition(string $id, string $action, ?string $actorUserId): Subscription
    {
        $subscription = Subscription::query()->findOrFail($id);
        $allowed = ['pause' => ['active'], 'resume' => ['paused'], 'cancel' => ['active', 'paused']][$action];

        if (! in_array($subscription->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('subscriptions.invalid_transition', ['status' => $subscription->status, 'action' => __("subscriptions.actions.{$this->eventOf($action)}")])]);
        }

        return match ($action) {
            'pause' => $this->lifecycle->pauseSubscription($id, 'Paused through the recurring billing API', null, $actorUserId),
            'resume' => $this->lifecycle->reactivateSubscription($id, $actorUserId),
            default => $this->lifecycle->cancelSubscription(new CancelSubscriptionDTO(subscriptionId: $id, reason: 'Cancelled through the recurring billing API', actorUserId: $actorUserId, refund: false)),
        };
    }

    /**
     * Bills the periods due today; returns the invoices created.
     *
     * @return array{invoices_created: int, invoice_ids: list<?string>}
     */
    public function bill(string $id, ?string $actorUserId): array
    {
        $subscription = Subscription::query()->findOrFail($id);
        $outcome = $this->billing->billSubscription((string) $subscription->id, CarbonImmutable::today(), CarbonImmutable::today(), null, $actorUserId);

        return [
            'invoices_created' => count($outcome['schedules']),
            'invoice_ids' => array_map(fn (InvoiceSchedule $schedule): ?string => $schedule->invoice_id, $outcome['schedules']),
        ];
    }

    /**
     * The billed and failed periods in the shape of the legacy billing runs, latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(Subscription $subscription): array
    {
        return InvoiceSchedule::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_kind', 'recurring')
            ->whereIn('status', ['invoiced', 'failed'])
            ->orderByDesc('service_period_start')
            ->get()
            ->map(fn (InvoiceSchedule $schedule): array => [
                'id' => (string) $schedule->id,
                'tenant_id' => (string) $schedule->tenant_id,
                'subscription_id' => (string) $schedule->subscription_id,
                'invoice_id' => $schedule->invoice_id,
                'period_start' => $schedule->service_period_start?->toDateString(),
                'period_end' => $schedule->service_period_end?->toDateString(),
                'status' => $schedule->status === 'invoiced' ? 'success' : 'failed',
                'error' => $schedule->error,
                'created_at' => $schedule->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * The legacy representation of a subscription.
     *
     * @return array<string, mixed>
     */
    public function present(Subscription $subscription, bool $withDetails = false): array
    {
        $subscription->loadMissing(['plan', 'customer']);
        $customer = $subscription->customer;
        $closed = $subscription->isCancelled();

        $data = [
            'id' => (string) $subscription->id,
            'tenant_id' => (string) $subscription->tenant_id,
            'contact_id' => (string) $subscription->customer_id,
            'subscription_number' => $subscription->subscription_number,
            'name' => $subscription->name,
            'status' => $subscription->status,
            'interval' => $this->plans->legacyInterval($subscription->plan?->billing_interval),
            'interval_count' => (int) ($subscription->plan->interval_count ?? 1),
            'currency' => $subscription->currency,
            'start_date' => $subscription->start_date?->toDateString(),
            'end_date' => $subscription->term_end_date?->toDateString(),
            'next_billing_date' => $closed ? null : $subscription->current_period_start?->toDateString(),
            'last_billed_at' => $subscription->last_billed_at?->toIso8601String(),
            'auto_renew' => (bool) $subscription->auto_renew,
            'notify_days_before' => (int) $subscription->renewal_notice_days,
            'renewal_notified_at' => $subscription->renewal_notified_at?->toIso8601String(),
            'notes' => $subscription->notes,
            'created_at' => $subscription->created_at?->toIso8601String(),
            'updated_at' => $subscription->updated_at?->toIso8601String(),
            'contact' => $customer === null ? null : [
                'id' => (string) $customer->id,
                'name' => $customer->company_name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
            ],
        ];

        if ($withDetails) {
            $data['items'] = $subscription->items()->orderBy('created_at')->get()->map(fn (SubscriptionItem $item): array => [
                'id' => (string) $item->id,
                'subscription_id' => (string) $item->subscription_id,
                'product_id' => $item->metadata['catalog_product_id'] ?? null,
                'description' => $item->description,
                'quantity' => Money::round4((string) $item->getRawOriginal('quantity')),
                'unit_price' => Money::round4((string) $item->getRawOriginal('unit_price')),
                'tax_rate' => $item->tax_rate !== null ? Money::round2((string) $item->getRawOriginal('tax_rate')) : null,
                'discount_percentage' => Money::round2((string) $item->getRawOriginal('discount_percentage')),
                'line_total' => Money::round2((string) $item->getRawOriginal('subtotal_recurring')),
            ])->values()->all();
            $data['runs'] = $this->runs($subscription);
        }

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{item_type: string, description: string, quantity: string, unit_price: string, discount_percentage: string, tax_rate: string, catalog_product_id: ?string}>
     */
    private function items(array $items): array
    {
        return array_values(array_map(fn (array $item): array => [
            'item_type' => 'charge',
            'description' => mb_substr((string) $item['description'], 0, 255),
            'quantity' => Money::round4((string) $item['quantity']),
            'unit_price' => Money::round4((string) $item['unit_price']),
            'discount_percentage' => Money::round2((string) ($item['discount_percentage'] ?? '0')),
            'tax_rate' => Money::round2((string) $item['tax_rate']),
            'catalog_product_id' => $item['product_id'] ?? null,
        ], $items));
    }

    private function eventOf(string $action): string
    {
        return ['pause' => 'paused', 'resume' => 'resumed', 'cancel' => 'cancelled'][$action];
    }
}
