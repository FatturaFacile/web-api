<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Subscription as LegacySubscription;
use App\Models\SubscriptionItem as LegacyItem;
use App\Models\SubscriptionRun as LegacyRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;
use Throwable;

/**
 * Copies the subscriptions of the former recurring billing (`subscriptions`, `subscription_items`,
 * `subscription_runs`) into the subscription engine (`sub_subscriptions`), keeping their ids, numbers and billing
 * state: the next billing date becomes the start of the next period to bill, the billed runs become invoiced
 * periods, so no period is billed twice. Keyed on the legacy id (`legacy_subscription_id`): running it again copies
 * only what is missing. The legacy rows are never changed or deleted.
 */
final readonly class LegacySubscriptionMigrator
{
    public function __construct(
        private LegacyPlanCatalog $plans,
        private PricingCalculator $pricing,
    ) {}

    /**
     * @return array{migrated: int, already_migrated: int, skipped_without_tenant: int, failed: int}
     */
    public function migrate(): array
    {
        $counts = ['migrated' => 0, 'already_migrated' => 0, 'skipped_without_tenant' => 0, 'failed' => 0];

        LegacySubscription::withoutGlobalScopes()->withTrashed()->orderBy('created_at')->orderBy('id')->each(function (LegacySubscription $legacy) use (&$counts): void {
            if ($legacy->tenant_id === null) {
                $counts['skipped_without_tenant']++;

                return;
            }

            if (Subscription::withoutGlobalScopes()->withTrashed()->where('legacy_subscription_id', $legacy->id)->orWhere('id', $legacy->id)->exists()) {
                $counts['already_migrated']++;

                return;
            }

            try {
                DB::transaction(fn () => $this->copy($legacy));
                $counts['migrated']++;
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::warning('Legacy subscription not migrated', ['subscription_id' => $legacy->id, 'error' => $e->getMessage()]);
            }
        });

        return $counts;
    }

    /**
     * Copies one legacy subscription now (a carrier row just created); does nothing when it is already copied.
     */
    public function copyOne(LegacySubscription $legacy): Subscription
    {
        return DB::transaction(function () use ($legacy): Subscription {
            $existing = Subscription::withoutGlobalScopes()->withTrashed()->where('legacy_subscription_id', $legacy->id)->orWhere('id', $legacy->id)->first();

            if ($existing === null) {
                $this->copy($legacy);
            }

            return Subscription::withoutGlobalScopes()->withTrashed()->whereKey($legacy->id)->firstOrFail();
        });
    }

    private function copy(LegacySubscription $legacy): void
    {
        $tenantId = (string) $legacy->tenant_id;
        $interval = $legacy->getRawOriginal('interval') ?: 'monthly';
        $count = max(1, (int) $legacy->interval_count);
        $plan = $this->plans->plan($tenantId, (string) $interval, $count, (string) ($legacy->currency ?: 'EUR'));
        $start = CarbonImmutable::parse($legacy->getRawOriginal('start_date'));
        $endDate = $legacy->getRawOriginal('end_date') !== null ? CarbonImmutable::parse($legacy->getRawOriginal('end_date')) : null;
        $nextBilling = $legacy->getRawOriginal('next_billing_date') !== null ? CarbonImmutable::parse($legacy->getRawOriginal('next_billing_date')) : null;
        $periodStart = $nextBilling ?? ($endDate?->addDay() ?? $start);
        [, $periodEnd] = BillingCalendar::period($start, $periodStart, $plan->billing_interval, $count, BillingCalendar::ANNIVERSARY);
        $status = in_array($legacy->status, ['active', 'paused', 'cancelled', 'expired'], true) ? $legacy->status : 'active';
        $number = (string) $legacy->subscription_number;

        if (Subscription::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->where('subscription_number', $number)->exists()) {
            $number = mb_substr($number.'-L', 0, 50);
        }

        $paymentTerms = Contact::withoutGlobalScopes()->whereKey($legacy->contact_id)->value('payment_terms_days');
        $closedOn = in_array($status, ['cancelled', 'expired'], true) ? CarbonImmutable::parse($legacy->getRawOriginal('updated_at') ?? now())->startOfDay() : null;

        $subscription = new Subscription(['id' => (string) $legacy->id]);
        $subscription->forceFill([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'subscription_number' => $number,
            'name' => $legacy->name,
            'customer_id' => (string) $legacy->contact_id,
            'plan_id' => $plan->id,
            'status' => $status,
            'start_date' => $start->toDateString(),
            'billing_anchor_date' => $start->toDateString(),
            'billing_alignment' => BillingCalendar::ANNIVERSARY,
            'current_period_start' => $periodStart->toDateString(),
            'current_period_end' => $periodEnd->toDateString(),
            'term_end_date' => $endDate?->toDateString(),
            'renewal_type' => $legacy->auto_renew ? 'evergreen' : 'manual',
            'contract_term_months' => BillingCalendar::monthsPerPeriod($plan->billing_interval, $count),
            'auto_renew' => (bool) $legacy->auto_renew,
            'cancellation_notice_days' => 0,
            'renewal_notice_days' => (int) $legacy->notify_days_before,
            'renewal_notified_at' => $legacy->getRawOriginal('renewal_notified_at'),
            'last_billed_at' => $legacy->getRawOriginal('last_billed_at'),
            'cancelled_at' => $status === 'cancelled' ? $closedOn : null,
            'cancellation_effective_date' => $closedOn?->toDateString(),
            'cancellation_type' => $status === 'cancelled' ? 'immediate' : null,
            'paused_at' => $status === 'paused' ? $legacy->getRawOriginal('updated_at') : null,
            'currency' => strtoupper((string) ($legacy->currency ?: 'EUR')),
            'billing_day_of_month' => (int) $start->day,
            'payment_terms_days' => (int) ($paymentTerms ?? 30),
            'payment_method' => 'bank_transfer',
            'mrr' => '0.00',
            'arr' => '0.00',
            'is_locked' => false,
            'notes' => $legacy->notes,
            'metadata' => ['source' => 'legacy_subscriptions'],
            'legacy_subscription_id' => (string) $legacy->id,
            'created_at' => $legacy->getRawOriginal('created_at'),
            'deleted_at' => $legacy->getRawOriginal('deleted_at'),
        ])->save();

        foreach (LegacyItem::query()->where('subscription_id', $legacy->id)->orderBy('created_at')->get() as $legacyItem) {
            $item = new SubscriptionItem(['id' => (string) $legacyItem->id]);
            $item->forceFill([
                'tenant_id' => $tenantId,
                'subscription_id' => $subscription->id,
                'item_type' => 'charge',
                'description' => mb_substr((string) $legacyItem->description, 0, 255),
                'quantity' => Money::round4((string) $legacyItem->getRawOriginal('quantity')),
                'unit_price' => Money::round4((string) $legacyItem->getRawOriginal('unit_price')),
                'discount_percentage' => Money::round2((string) $legacyItem->getRawOriginal('discount_percentage')),
                'tax_rate' => Money::round2((string) $legacyItem->getRawOriginal('tax_rate')),
                'price_factor' => '1',
                'legacy_item_id' => (string) $legacyItem->id,
                'metadata' => array_filter(['catalog_product_id' => $legacyItem->product_id]),
            ])->save();
            $item->forceFill(['subtotal_recurring' => $this->pricing->itemAmount($item, $plan)])->save();
        }

        foreach (LegacyRun::withoutGlobalScopes()->where('subscription_id', $legacy->id)->orderBy('period_start')->get() as $run) {
            InvoiceSchedule::create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'subscription_id' => $subscription->id,
                'billing_kind' => 'recurring',
                'scheduled_billing_date' => $run->getRawOriginal('period_start'),
                'service_period_start' => $run->getRawOriginal('period_start'),
                'service_period_end' => $run->getRawOriginal('period_end'),
                'estimated_amount' => Money::round2((string) (Invoice::withoutGlobalScopes()->whereKey($run->invoice_id)->value('total_gross') ?? '0')),
                'net_amount' => Money::round2((string) (Invoice::withoutGlobalScopes()->whereKey($run->invoice_id)->value('total_net') ?? '0')),
                'status' => $run->status === 'success' && $run->invoice_id !== null ? 'invoiced' : 'failed',
                'invoice_id' => $run->invoice_id,
                'error' => $run->error,
            ]);
        }

        $subscription->load(['plan', 'items']);
        $periodAmount = Money::sum($subscription->items->map(fn (SubscriptionItem $item): string => $this->pricing->itemAmount($item, $plan)));
        $mrr = in_array($status, ['active'], true) ? $this->pricing->monthly($periodAmount, $plan->billing_interval, $count) : '0.00';
        $subscription->forceFill(['mrr' => $mrr, 'arr' => Money::round2(Money::mul($mrr, '12'))])->save();

        SubscriptionVersion::create([
            'tenant_id' => $tenantId,
            'subscription_id' => $subscription->id,
            'version_number' => 1,
            'effective_date' => $start->toDateString(),
            'change_type' => 'initial',
            'mrr_before' => '0.00',
            'mrr_after' => $mrr,
            'mrr_delta' => $mrr,
            'proration_amount' => '0.00',
            'snapshot_payload' => $subscription->attributesToArray(),
            'change_reason' => 'Migrated from the legacy recurring billing',
        ]);
    }
}
