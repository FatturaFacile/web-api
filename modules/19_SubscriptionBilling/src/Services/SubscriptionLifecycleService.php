<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Events\SubscriptionActivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCreatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionDowngradedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionSuspendedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionUpgradedEvent;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\UsageMetric;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;

/**
 * Lifecycle of the subscriptions: creation (trial, alignment, term), activation, pause and resume, plan and quantity
 * changes prorated from the effective date (a proration invoice for a charge, a credit note for a refund, both
 * through the invoicing services), and cancellation, immediate or at the end of the term, with a reason.
 *
 * Every operation locks the subscription row for its transaction.
 */
final readonly class SubscriptionLifecycleService implements SubscriptionLifecycleServiceInterface
{
    public function __construct(
        private PricingCalculator $pricing,
        private SubscriptionHistory $history,
        private RenewalService $renewals,
        private SubscriptionInvoiceIssuer $issuer,
        private RevenueRecognitionServiceInterface $revenue,
        private DocumentNumberingService $numbering,
        private DocumentEventRecorder $documentEvents,
    ) {}

    /**
     * Creates a subscription: in trial when the plan (or the request) gives trial days, active otherwise.
     *
     * @throws ValidationException when the plan is not an active plan of the tenant
     */
    public function createSubscription(CreateSubscriptionDTO $dto, ?string $actorUserId = null): Subscription
    {
        return DB::transaction(function () use ($dto, $actorUserId): Subscription {
            $plan = SubscriptionPlan::query()->with(['pricingTiers', 'features'])->where('tenant_id', $dto->tenantId)->whereKey($dto->planId)->first();

            if ($plan === null || ! $plan->is_active) {
                throw ValidationException::withMessages(['plan_id' => __('subscriptions_errors.plan.not_active')]);
            }

            $start = CarbonImmutable::parse($dto->startDate)->startOfDay();
            $trialDays = max(0, $dto->trialDays ?? (int) $plan->trial_period_days);
            $trialEnd = $trialDays > 0 ? $start->addDays($trialDays - 1) : null;
            $firstBilling = $trialEnd?->addDay() ?? $start;
            $alignment = $dto->billingAlignment ?? (string) ($plan->billing_alignment ?? BillingCalendar::ANNIVERSARY);
            $interval = (string) $plan->billing_interval;
            $count = (int) $plan->interval_count;
            [$periodStart, $periodEnd] = BillingCalendar::period($firstBilling, $firstBilling, $interval, $count, $alignment);
            $termMonths = max(1, $dto->contractTermMonths);
            $termEnd = $dto->termEndDate !== null
                ? CarbonImmutable::parse($dto->termEndDate)
                : ($dto->includePlanItem ? $start->addMonthsNoOverflow($termMonths)->subDay() : null);

            $attributes = [
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'subscription_number' => $this->numbering->next('subscription', $start->year, $dto->tenantId)['number'],
                'name' => $dto->name ?? $plan->name,
                'customer_id' => $dto->customerId,
                'plan_id' => $plan->id,
                'status' => $trialEnd !== null ? 'trialing' : 'active',
                'start_date' => $start->toDateString(),
                'billing_anchor_date' => $firstBilling->toDateString(),
                'billing_alignment' => $alignment,
                'current_period_start' => $periodStart->toDateString(),
                'current_period_end' => $periodEnd->toDateString(),
                'trial_end_date' => $trialEnd?->toDateString(),
                'term_end_date' => $termEnd?->toDateString(),
                'renewal_type' => $dto->autoRenew ? 'evergreen' : 'manual',
                'contract_term_months' => $termMonths,
                'auto_renew' => $dto->autoRenew,
                'cancellation_notice_days' => $dto->cancellationNoticeDays,
                'renewal_notice_days' => $dto->renewalNoticeDays,
                'renewal_price_increase_percent' => $dto->renewalPriceIncreasePercent !== null ? Money::round($dto->renewalPriceIncreasePercent, 4) : null,
                'billing_day_of_month' => $dto->billingDayOfMonth,
                'payment_terms_days' => $dto->paymentTermsDays,
                'payment_method' => $dto->paymentMethod,
                'bank_account_id' => $dto->bankAccountId,
                'currency' => $dto->currency ?? $plan->currency,
                'mrr' => '0.00',
                'arr' => '0.00',
                'is_locked' => false,
                'notes' => $dto->notes,
                'metadata' => $dto->metadata,
                'legacy_subscription_id' => $dto->legacySubscriptionId,
            ];

            if ($dto->subscriptionId !== null) {
                $attributes['id'] = $dto->subscriptionId;
            }

            $subscription = Subscription::create($attributes);
            $this->createItems($subscription, $plan, $dto);

            [, $mrr] = $this->history->refreshMrr($subscription, $subscription->status !== 'trialing');
            $this->history->version($subscription, 'initial', '0', $mrr, $start, 'Subscription created', $actorUserId);
            $this->history->audit('SUBSCRIPTION_CREATED', $subscription, null, ['plan_id' => $plan->id, 'mrr' => $mrr, 'status' => $subscription->status]);
            $this->documentEvents->record($subscription, 'created');

            $this->history->publish(new SubscriptionCreatedEvent(
                subscriptionId: (string) $subscription->id,
                subscriptionNumber: (string) $subscription->subscription_number,
                customerId: (string) $subscription->customer_id,
                planId: (string) $plan->id,
                mrr: (float) $mrr,
                arr: (float) Money::round2(Money::mul($mrr, '12')),
                currency: (string) $subscription->currency,
                tenantId: (string) $subscription->tenant_id,
                companyId: $this->history->companyOf($subscription)
            ));

            return $subscription->refresh()->load(['plan', 'items']);
        });
    }

    /**
     * Activates a subscription waiting for approval (pending or draft).
     */
    public function activateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription
    {
        return $this->locked($subscriptionId, function (Subscription $subscription) use ($actorUserId): Subscription {
            $this->assertStatus($subscription, ['pending', 'draft'], 'activate');
            $today = CarbonImmutable::today();
            $status = $subscription->trial_end_date !== null && $subscription->trial_end_date->gte($today) ? 'trialing' : 'active';
            $subscription->forceFill(['status' => $status, 'is_locked' => false])->save();

            [$before, $after] = $this->history->refreshMrr($subscription, $status === 'active');
            $this->history->version($subscription, 'activation', $before, $after, $today, 'Subscription activated', $actorUserId);
            $this->history->audit('SUBSCRIPTION_ACTIVATED', $subscription, ['status' => 'pending'], ['status' => $status]);

            $this->history->publish(new SubscriptionActivatedEvent(
                subscriptionId: (string) $subscription->id,
                subscriptionNumber: (string) $subscription->subscription_number,
                customerId: (string) $subscription->customer_id,
                mrr: (float) $after,
                startDate: ($subscription->start_date ?? $today)->toDateString(),
                tenantId: (string) $subscription->tenant_id,
                companyId: $this->history->companyOf($subscription)
            ));

            return $subscription;
        });
    }

    /**
     * Suspends a subscription without a resume date (administrative stop): a pause until reactivated.
     */
    public function suspendSubscription(string $subscriptionId, string $reason, ?string $actorUserId = null): Subscription
    {
        return $this->pauseSubscription($subscriptionId, $reason, null, $actorUserId);
    }

    /**
     * Pauses a live subscription: it is not billed until it resumes, by itself on `$resumeOn` or when reactivated.
     */
    public function pauseSubscription(string $subscriptionId, string $reason, ?string $resumeOn, ?string $actorUserId = null): Subscription
    {
        return $this->locked($subscriptionId, function (Subscription $subscription) use ($reason, $resumeOn, $actorUserId): Subscription {
            $this->assertStatus($subscription, ['active', 'trialing', 'past_due'], 'pause');
            $today = CarbonImmutable::today();
            $previous = $subscription->status;

            $subscription->forceFill([
                'status' => 'paused',
                'paused_at' => now(),
                'pause_reason' => mb_substr($reason, 0, 255),
                'resume_on' => $resumeOn !== null ? CarbonImmutable::parse($resumeOn)->toDateString() : null,
            ])->save();

            [$before, $after] = $this->history->refreshMrr($subscription, false);
            $this->history->version($subscription, 'pause', $before, $after, $today, $reason, $actorUserId);
            $this->history->audit('SUBSCRIPTION_PAUSED', $subscription, ['status' => $previous], ['status' => 'paused', 'reason' => $reason, 'resume_on' => $resumeOn]);
            $this->documentEvents->record($subscription, 'paused', ['reason' => $reason]);

            $this->history->publish(new SubscriptionSuspendedEvent(
                subscriptionId: (string) $subscription->id,
                subscriptionNumber: (string) $subscription->subscription_number,
                reason: $reason,
                tenantId: (string) $subscription->tenant_id,
                companyId: $this->history->companyOf($subscription)
            ));

            return $subscription;
        });
    }

    /**
     * Resumes a paused or suspended subscription; the periods that ended during the pause are not billed.
     */
    public function reactivateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription
    {
        return $this->locked($subscriptionId, function (Subscription $subscription) use ($actorUserId): Subscription {
            $this->assertStatus($subscription, ['paused', 'suspended'], 'resume');
            $this->renewals->resume($subscription, CarbonImmutable::today(), $actorUserId, 'Subscription resumed');

            return $subscription;
        });
    }

    /**
     * Moves to a plan with a higher (or equal) price per period.
     */
    public function upgradeSubscription(UpgradeSubscriptionDTO $dto): Subscription
    {
        return $this->changePlan($dto->subscriptionId, $dto->newPlanId, $dto->quantity, 'upgrade', $dto->effectiveDate, $dto->changeReason, $dto->actorUserId);
    }

    /**
     * Moves to a plan with a lower (or equal) price per period.
     */
    public function downgradeSubscription(DowngradeSubscriptionDTO $dto): Subscription
    {
        return $this->changePlan($dto->subscriptionId, $dto->newPlanId, $dto->quantity, 'downgrade', $dto->effectiveDate, $dto->changeReason, $dto->actorUserId);
    }

    /**
     * Changes the quantity (seats) of the plan item, prorated from the effective date.
     */
    public function changeQuantity(string $subscriptionId, string $quantity, ?string $effectiveDate, ?string $reason, ?string $actorUserId): Subscription
    {
        return $this->locked($subscriptionId, function (Subscription $subscription) use ($quantity, $effectiveDate, $reason, $actorUserId): Subscription {
            $this->assertChangeable($subscription);
            $planItem = $subscription->items->firstWhere('item_type', 'plan');

            if ($planItem === null) {
                throw ValidationException::withMessages(['quantity' => __('subscriptions_errors.subscription.no_quantity_item')]);
            }

            $old = Money::round4((string) $planItem->getRawOriginal('quantity'));
            $new = Money::round4($quantity);
            $changeType = Money::compare($new, $old) >= 0 ? 'quantity_increase' : 'quantity_decrease';

            return $this->applyChange($subscription, function (Subscription $subscription) use ($planItem, $new): void {
                $planItem->update(['quantity' => $new]);
            }, $changeType, $this->effectiveDate($effectiveDate), $reason ?? "Quantity {$old} -> {$new}", $actorUserId, ['quantity_before' => $old, 'quantity_after' => $new]);
        });
    }

    /**
     * Cancels a subscription, immediately (refunding the unused part of the billed period when asked) or at the end
     * of the term (respecting the cancellation notice of an automatically renewing term).
     */
    public function cancelSubscription(CancelSubscriptionDTO $dto): Subscription
    {
        return $this->locked($dto->subscriptionId, function (Subscription $subscription) use ($dto): Subscription {
            if ($subscription->isCancelled()) {
                throw ValidationException::withMessages(['status' => __('subscriptions_errors.subscription.already_closed')]);
            }

            $today = CarbonImmutable::today();
            $common = [
                'cancellation_reason' => mb_substr($dto->reason, 0, 150),
                'cancellation_reason_code' => $dto->reasonCode,
                'cancellation_type' => $dto->cancellationType,
            ];

            if ($dto->cancellationType === 'end_of_term') {
                $effective = $this->endOfTermDate($subscription, $today);
                $subscription->forceFill([...$common, 'cancel_at_period_end' => true, 'cancellation_effective_date' => $effective->toDateString()])->save();
                $mrr = Money::round2((string) $subscription->getRawOriginal('mrr'));
                $this->history->version($subscription, 'cancellation_scheduled', $mrr, $mrr, $effective, $dto->reason, $dto->actorUserId, '0', ['reason_code' => $dto->reasonCode]);
                $this->history->audit('SUBSCRIPTION_CANCELLATION_SCHEDULED', $subscription, null, ['effective_date' => $effective->toDateString(), 'reason_code' => $dto->reasonCode]);

                return $subscription;
            }

            $effective = $dto->cancellationDate !== null ? CarbonImmutable::parse($dto->cancellationDate)->startOfDay() : $today;
            $refund = $dto->refund ? $this->refundUnusedPeriod($subscription, $effective, $dto->actorUserId, 'Refund of the unused period after cancellation') : null;
            $subscription->forceFill([...$common, 'cancelled_at' => now(), 'cancel_at_period_end' => false, 'cancellation_effective_date' => $effective->toDateString()])->save();

            if ($effective->lte($today)) {
                $this->renewals->close($subscription, 'cancelled', $effective, $dto->reason, $dto->actorUserId);
            } else {
                $mrr = Money::round2((string) $subscription->getRawOriginal('mrr'));
                $this->history->version($subscription, 'cancellation_scheduled', $mrr, $mrr, $effective, $dto->reason, $dto->actorUserId, $refund ?? '0', ['reason_code' => $dto->reasonCode]);
            }

            return $subscription;
        });
    }

    /**
     * Proration of a plan change at a date, against the billed period containing it.
     *
     * @return array{billed_period_start: ?string, billed_period_end: ?string, remaining_days: int, total_period_days: int, current_period_amount: string, new_period_amount: string, net_proration: string}
     */
    public function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan, CarbonInterface $effectiveDate, ?string $quantity = null): array
    {
        $subscription->loadMissing(['plan.pricingTiers', 'items']);
        $effective = CarbonImmutable::instance($effectiveDate)->startOfDay();
        $current = $this->periodAmount($subscription, $subscription->plan);
        $planItem = $subscription->items->firstWhere('item_type', 'plan');
        $newQuantity = $quantity ?? (string) ($planItem?->getRawOriginal('quantity') ?? '1');
        $planShareNow = $planItem !== null ? $this->pricing->itemAmount($planItem, $subscription->plan) : '0';
        $simulated = $planItem !== null ? (clone $planItem)->forceFill(['quantity' => $newQuantity, 'plan_id' => $newPlan->id]) : null;
        $planShareNew = $simulated !== null ? $this->pricing->itemAmount($simulated, $newPlan) : '0';
        $new = Money::round2(Money::add(Money::sub($current, $planShareNow), $planShareNew));
        $billed = $this->billedPeriod($subscription, $effective);
        [$remaining, $total, $amount] = $this->prorate($billed, $effective, Money::sub($new, $current));

        return [
            'billed_period_start' => $billed?->service_period_start?->toDateString(),
            'billed_period_end' => $billed?->service_period_end?->toDateString(),
            'remaining_days' => $remaining,
            'total_period_days' => $total,
            'current_period_amount' => $current,
            'new_period_amount' => $new,
            'net_proration' => $amount,
        ];
    }

    private function changePlan(string $subscriptionId, string $newPlanId, ?string $quantity, string $changeType, ?string $effectiveDate, ?string $reason, ?string $actorUserId): Subscription
    {
        return $this->locked($subscriptionId, function (Subscription $subscription) use ($newPlanId, $quantity, $changeType, $effectiveDate, $reason, $actorUserId): Subscription {
            $this->assertChangeable($subscription);
            $newPlan = SubscriptionPlan::query()->with('pricingTiers')->where('tenant_id', $subscription->tenant_id)->whereKey($newPlanId)->first();

            if ($newPlan === null || ! $newPlan->is_active) {
                throw ValidationException::withMessages(['new_plan_id' => __('subscriptions_errors.plan.not_active')]);
            }

            $oldPlan = $subscription->plan;

            if ($oldPlan !== null && ($newPlan->currency !== $subscription->currency || $newPlan->billing_interval !== $oldPlan->billing_interval || (int) $newPlan->interval_count !== (int) $oldPlan->interval_count)) {
                throw ValidationException::withMessages(['new_plan_id' => __('subscriptions_errors.plan.currency_interval_mismatch')]);
            }

            $preview = $this->calculateProration($subscription, $newPlan, $this->effectiveDate($effectiveDate), $quantity);
            $direction = Money::compare($preview['new_period_amount'], $preview['current_period_amount']);

            if (($changeType === 'upgrade' && $direction < 0) || ($changeType === 'downgrade' && $direction > 0)) {
                throw ValidationException::withMessages(['new_plan_id' => __($changeType === 'upgrade' ? 'subscriptions_errors.plan.use_downgrade' : 'subscriptions_errors.plan.use_upgrade')]);
            }

            $oldPlanId = (string) $subscription->plan_id;
            $updated = $this->applyChange($subscription, function (Subscription $subscription) use ($newPlan, $quantity): void {
                $subscription->forceFill(['plan_id' => $newPlan->id])->save();
                $subscription->setRelation('plan', $newPlan);

                foreach ($subscription->items->where('item_type', 'plan') as $item) {
                    $item->update(array_filter([
                        'plan_id' => $newPlan->id,
                        'description' => $newPlan->name,
                        'unit_price' => Money::round4((string) $newPlan->getRawOriginal('base_price')),
                        'quantity' => $quantity !== null ? Money::round4($quantity) : null,
                    ], fn ($value): bool => $value !== null));
                }
            }, $changeType, $this->effectiveDate($effectiveDate), $reason ?? "Plan {$changeType} to {$newPlan->name}", $actorUserId, ['plan_before' => $oldPlanId, 'plan_after' => (string) $newPlan->id]);

            $version = $updated->versions()->first();
            $eventClass = $changeType === 'upgrade' ? SubscriptionUpgradedEvent::class : SubscriptionDowngradedEvent::class;
            $this->history->publish(new $eventClass(
                subscriptionId: (string) $updated->id,
                oldPlanId: $oldPlanId,
                newPlanId: (string) $newPlan->id,
                mrrDelta: (float) ($version?->getRawOriginal('mrr_delta') ?? 0),
                prorationAmount: (float) ($version?->getRawOriginal('proration_amount') ?? 0),
                tenantId: (string) $updated->tenant_id,
                companyId: $this->history->companyOf($updated)
            ));

            return $updated;
        });
    }

    /**
     * Applies a price change to a locked subscription and settles its proration against the billed period.
     *
     * @param  Closure(Subscription): void  $mutate
     * @param  array<string, mixed>  $extra
     */
    private function applyChange(Subscription $subscription, Closure $mutate, string $changeType, CarbonImmutable $effective, string $reason, ?string $actorUserId, array $extra): Subscription
    {
        $oldAmount = $this->periodAmount($subscription, $subscription->plan);
        $mutate($subscription);
        $subscription->unsetRelation('items');
        $subscription->load(['plan.pricingTiers', 'items']);
        $newAmount = $this->periodAmount($subscription, $subscription->plan);

        $billed = $this->billedPeriod($subscription, $effective);
        [, , $proration] = $this->prorate($billed, $effective, Money::sub($newAmount, $oldAmount));
        $documents = [];

        if ($billed !== null && Money::isPositive($proration)) {
            $documents = $this->chargeProration($subscription, $billed, $effective, $proration, $changeType, $actorUserId);
        } elseif ($billed !== null && Money::isNegative($proration)) {
            $documents = $this->creditProration($subscription, $billed, $effective, Money::abs($proration), "Proration credit: {$changeType}", $actorUserId);
        }

        foreach ($subscription->items as $item) {
            $item->update(['subtotal_recurring' => $this->pricing->itemAmount($item, $subscription->plan)]);
        }

        [$mrrBefore, $mrrAfter] = $this->history->refreshMrr($subscription, ! in_array($subscription->status, ['trialing', 'paused', 'suspended'], true));
        $this->history->version($subscription, $changeType, $mrrBefore, $mrrAfter, $effective, $reason, $actorUserId, $proration, [...$extra, ...$documents]);
        $this->history->audit('SUBSCRIPTION_'.strtoupper($changeType), $subscription, ['period_amount' => $oldAmount, 'mrr' => $mrrBefore], ['period_amount' => $newAmount, 'mrr' => $mrrAfter, 'proration' => $proration, ...$documents]);

        return $subscription->refresh()->load(['plan', 'items']);
    }

    /**
     * Invoices the positive proration of a change for the rest of the billed period.
     *
     * @return array<string, string>
     */
    private function chargeProration(Subscription $subscription, InvoiceSchedule $billed, CarbonImmutable $effective, string $amount, string $changeType, ?string $actorUserId): array
    {
        $end = CarbonImmutable::parse($billed->service_period_end);
        $date = $this->documentDate($effective);
        $description = "Proration ({$changeType}) {$effective->toDateString()} - {$end->toDateString()}";
        $schedule = InvoiceSchedule::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'billing_kind' => 'proration',
            'scheduled_billing_date' => $date->toDateString(),
            'service_period_start' => $effective->toDateString(),
            'service_period_end' => $end->toDateString(),
            'estimated_amount' => '0.00',
            'net_amount' => $amount,
            'status' => 'processing',
        ]);

        $invoice = $this->issuer->invoice($subscription, [[
            'description' => $description,
            'quantity' => '1',
            'unit_price' => $amount,
            'tax_rate' => $this->issuer->taxRate($subscription, null, $subscription->plan),
        ]], $date, "Subscription {$subscription->subscription_number}: {$description}", $actorUserId);

        $schedule->update(['status' => 'invoiced', 'invoice_id' => $invoice->id, 'estimated_amount' => Money::round2((string) $invoice->total_gross)]);
        $this->revenue->scheduleDocument($subscription, 'invoice', (string) $invoice->id, $schedule, [[
            'description' => $description, 'amount' => $amount, 'method' => 'straight_line', 'start' => $effective, 'end' => $end,
        ]]);

        return ['proration_invoice_id' => (string) $invoice->id];
    }

    /**
     * Credits the unused part of the billed period with a credit note on its invoice, releases the deferred revenue
     * it gave back and removes the credited months from the recognition. A draft invoice cannot be credited: the
     * amount is left on the version for the accountant.
     *
     * @return array<string, string>
     */
    private function creditProration(Subscription $subscription, InvoiceSchedule $billed, CarbonImmutable $effective, string $amount, string $label, ?string $actorUserId): array
    {
        $invoice = $billed->invoice_id !== null ? SalesInvoice::withoutGlobalScopes()->whereKey($billed->invoice_id)->first() : null;

        if ($invoice === null || $invoice->status !== 'posted') {
            return ['unapplied_credit' => $amount];
        }

        $amount = Money::min($amount, Money::round2((string) $invoice->total_net));
        $end = CarbonImmutable::parse($billed->service_period_end);
        $date = CarbonImmutable::today();
        $description = "{$label} {$effective->toDateString()} - {$end->toDateString()}";

        $creditNote = $this->issuer->creditNote($invoice, [[
            'description' => $description,
            'quantity' => '1',
            'unit_price' => $amount,
            'tax_rate' => $this->issuer->taxRate($subscription, null, $subscription->plan),
        ]], $date, "Subscription {$subscription->subscription_number}: {$description}", $actorUserId);

        $schedule = InvoiceSchedule::create([
            'tenant_id' => $subscription->tenant_id,
            'company_id' => $subscription->company_id,
            'subscription_id' => $subscription->id,
            'billing_kind' => 'credit',
            'scheduled_billing_date' => $date->toDateString(),
            'service_period_start' => $effective->toDateString(),
            'service_period_end' => $end->toDateString(),
            'estimated_amount' => Money::neg(Money::round2((string) $creditNote->total_gross)),
            'net_amount' => Money::neg($amount),
            'status' => 'invoiced',
            'invoice_id' => $invoice->id,
            'credit_note_id' => $creditNote->id,
        ]);

        $this->revenue->scheduleDocument($subscription, 'credit_note', (string) $creditNote->id, $schedule, [[
            'description' => $description, 'amount' => Money::neg($amount), 'method' => 'straight_line', 'start' => $effective, 'end' => $end,
        ]]);
        $this->revenue->releaseCredit($subscription, $creditNote, $amount, $date, $actorUserId);

        return ['credit_note_id' => (string) $creditNote->id];
    }

    /**
     * Refunds the unused days of the billed period from the effective date; returns the credited net amount.
     */
    private function refundUnusedPeriod(Subscription $subscription, CarbonImmutable $effective, ?string $actorUserId, string $label): ?string
    {
        $billed = $this->billedPeriod($subscription, $effective);

        if ($billed === null) {
            return null;
        }

        $periodAmount = Money::min($this->periodAmount($subscription, $subscription->plan), Money::round2((string) $billed->getRawOriginal('net_amount')));
        [, , $refund] = $this->prorate($billed, $effective, $periodAmount);

        if (! Money::isPositive($refund)) {
            return null;
        }

        $documents = $this->creditProration($subscription, $billed, $effective, $refund, $label, $actorUserId);
        $this->history->audit('SUBSCRIPTION_REFUNDED', $subscription, null, ['amount' => $refund, ...$documents]);

        return $refund;
    }

    /**
     * Effective date of a cancellation at the end of the term: after one more term when the notice period of an
     * automatically renewing term has passed; the next period when the subscription has no term.
     */
    private function endOfTermDate(Subscription $subscription, CarbonImmutable $today): CarbonImmutable
    {
        $termEnd = $subscription->term_end_date;

        if ($termEnd === null) {
            return $subscription->current_period_start ?? $today;
        }

        $deadline = $termEnd->subDays((int) $subscription->cancellation_notice_days);

        if ($subscription->auto_renew && $today->gt($deadline)) {
            return $termEnd->addDay()->addMonthsNoOverflow(max(1, (int) $subscription->contract_term_months));
        }

        return $termEnd->addDay();
    }

    /**
     * The invoiced recurring period containing a date.
     */
    private function billedPeriod(Subscription $subscription, CarbonImmutable $date): ?InvoiceSchedule
    {
        return InvoiceSchedule::query()
            ->where('subscription_id', $subscription->id)
            ->where('billing_kind', 'recurring')
            ->where('status', 'invoiced')
            ->whereDate('service_period_start', '<=', $date->toDateString())
            ->whereDate('service_period_end', '>=', $date->toDateString())
            ->orderByDesc('service_period_start')
            ->first();
    }

    /**
     * Share of an amount for the days left in a billed period from a date: [remaining days, period days, amount].
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function prorate(?InvoiceSchedule $billed, CarbonImmutable $effective, string $amount): array
    {
        if ($billed === null) {
            return [0, 0, '0.00'];
        }

        $start = CarbonImmutable::parse($billed->service_period_start);
        $end = CarbonImmutable::parse($billed->service_period_end);
        $total = BillingCalendar::days($start, $end);
        $remaining = BillingCalendar::days($effective->max($start), $end);

        return [$remaining, $total, Money::round2(Money::mul($amount, Money::div((string) $remaining, (string) max(1, $total))))];
    }

    /**
     * Recurring amount of one full period of the current items.
     */
    private function periodAmount(Subscription $subscription, ?SubscriptionPlan $plan): string
    {
        return Money::round2(Money::sum($subscription->items->map(fn (SubscriptionItem $item): string => $this->pricing->itemAmount($item, $plan))));
    }

    private function createItems(Subscription $subscription, SubscriptionPlan $plan, CreateSubscriptionDTO $dto): void
    {
        if ($dto->includePlanItem) {
            $item = SubscriptionItem::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'product_id' => $plan->product_id,
                'item_type' => 'plan',
                'description' => $plan->name,
                'quantity' => Money::round4($dto->quantity),
                'unit_price' => Money::round4((string) $plan->getRawOriginal('base_price')),
                'discount_percentage' => '0',
                'price_factor' => '1',
            ]);
            $item->update(['subtotal_recurring' => $this->pricing->itemAmount($item, $plan)]);
        }

        foreach ($dto->items as $line) {
            $item = SubscriptionItem::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'item_type' => $line['item_type'] ?? 'addon',
                'description' => mb_substr((string) ($line['description'] ?? 'Add-on'), 0, 255),
                'quantity' => Money::round4((string) ($line['quantity'] ?? '1')),
                'unit_price' => Money::round4((string) ($line['unit_price'] ?? '0')),
                'discount_percentage' => Money::round2((string) ($line['discount_percentage'] ?? '0')),
                'tax_rate' => isset($line['tax_rate']) ? Money::round2((string) $line['tax_rate']) : null,
                'metric_id' => $line['metric_id'] ?? null,
                'price_factor' => '1',
                'legacy_item_id' => $line['legacy_item_id'] ?? null,
                'metadata' => array_filter(['catalog_product_id' => $line['catalog_product_id'] ?? null]),
            ]);
            $item->update(['subtotal_recurring' => $this->pricing->itemAmount($item, $plan)]);
        }

        if (! $dto->includePlanItem) {
            return;
        }

        // Each metered feature of the plan whose metric exists becomes a usage item of the subscription.
        foreach ($plan->features->where('is_active', true)->where('metric_type', 'metered_quota') as $feature) {
            /** @var PlanFeature $feature */
            $metric = UsageMetric::query()->where('tenant_id', $subscription->tenant_id)->where('metric_code', $feature->feature_code)->first();

            if ($metric === null || $subscription->items()->where('metric_id', $metric->id)->exists()) {
                continue;
            }

            SubscriptionItem::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'item_type' => 'usage_meter',
                'description' => $feature->name,
                'quantity' => '0',
                'unit_price' => Money::round4((string) $feature->getRawOriginal('overage_unit_price')),
                'discount_percentage' => '0',
                'subtotal_recurring' => '0.00',
                'metric_id' => $metric->id,
                'price_factor' => '1',
            ]);
        }
    }

    /**
     * Runs an operation on the locked subscription of the active tenant.
     *
     * @template TResult
     *
     * @param  Closure(Subscription): TResult  $operation
     * @return TResult
     */
    private function locked(string $subscriptionId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($subscriptionId, $operation): mixed {
            $subscription = Subscription::query()->with(['plan.pricingTiers', 'items'])->whereKey($subscriptionId)->lockForUpdate()->firstOrFail();

            return $operation($subscription);
        });
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(Subscription $subscription, array $allowed, string $action): void
    {
        if (! in_array($subscription->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('subscriptions.invalid_transition', ['status' => $subscription->status, 'action' => $action])]);
        }
    }

    private function assertChangeable(Subscription $subscription): void
    {
        if ($subscription->is_locked) {
            throw ValidationException::withMessages(['status' => __('subscriptions_errors.subscription.pending_approval')]);
        }

        $this->assertStatus($subscription, ['active', 'trialing', 'past_due', 'paused'], 'change');
    }

    private function effectiveDate(?string $date): CarbonImmutable
    {
        return $date !== null ? CarbonImmutable::parse($date)->startOfDay() : CarbonImmutable::today();
    }

    /**
     * Date of a document for an effective date: never in the future.
     */
    private function documentDate(CarbonImmutable $effective): CarbonImmutable
    {
        $today = CarbonImmutable::today();

        return $effective->gt($today) ? $today : $effective;
    }
}
