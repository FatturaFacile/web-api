<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use App\Jobs\SendSubscriptionRenewalNotice;
use App\Services\Sales\DocumentEventRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionBilling\Contracts\RenewalServiceInterface;
use Modules\SubscriptionBilling\Events\RenewalForecastEvent;
use Modules\SubscriptionBilling\Events\SubscriptionActivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCancelledEvent;
use Modules\SubscriptionBilling\Events\SubscriptionReactivatedEvent;
use Modules\SubscriptionBilling\Events\SubscriptionRenewedEvent;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;

/**
 * Time-driven transitions of the subscriptions: renewal notices within the notice window, automatic renewals at the
 * end of the term (with the price increase of the plan or of the subscription), expirations of the subscriptions that
 * do not renew, cancellations that become effective, trials that convert and pauses that end.
 *
 * Every transition runs on a subscription locked by the caller, so the daily run and a billing run cannot apply it
 * twice.
 */
final readonly class RenewalService implements RenewalServiceInterface
{
    public function __construct(
        private SubscriptionHistory $history,
        private DocumentEventRecorder $documentEvents,
    ) {}

    /**
     * Daily processing of a tenant: notices, renewals, expirations, effective cancellations, trial conversions and
     * pause ends due today, plus the renewal opportunities of the subscriptions that do not renew by themselves.
     *
     * @return array{total_expiring: int, auto_renewed_count: int, expired_count: int, notices_sent: int, opportunities_generated: int, opportunities: list<array<string, mixed>>}
     */
    public function processExpiringSubscriptions(string $tenantId, int $lookaheadDays = 30): array
    {
        $today = CarbonImmutable::today();
        $ids = Subscription::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', Subscription::CLOSED_STATUSES)
            ->orderBy('id')
            ->pluck('id');

        $result = ['total_expiring' => 0, 'auto_renewed_count' => 0, 'expired_count' => 0, 'notices_sent' => 0, 'opportunities_generated' => 0, 'opportunities' => []];
        $projected = '0';

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $today, $lookaheadDays, &$result, &$projected): void {
                $subscription = Subscription::query()->with(['plan.pricingTiers', 'items', 'customer'])->whereKey($id)->lockForUpdate()->first();

                if ($subscription === null) {
                    return;
                }

                $before = $subscription->status;
                $renewedBefore = $subscription->term_end_date?->toDateString();
                $this->applyDueTransitions($subscription, $today, null);

                if ($subscription->status === 'expired' && $before !== 'expired') {
                    $result['expired_count']++;
                }

                if ($renewedBefore !== null && $subscription->term_end_date?->toDateString() !== $renewedBefore && ! $subscription->isCancelled()) {
                    $result['auto_renewed_count']++;
                }

                if ($subscription->isCancelled() || $subscription->term_end_date === null) {
                    return;
                }

                if ($this->sendNoticeIfDue($subscription, $today)) {
                    $result['notices_sent']++;
                }

                if ($subscription->term_end_date->lte($today->addDays($lookaheadDays))) {
                    $result['total_expiring']++;
                    $projected = Money::add($projected, Money::mul((string) $subscription->getRawOriginal('mrr'), '12'));

                    if (! $subscription->auto_renew) {
                        $result['opportunities'][] = $this->generateRenewalOpportunity($subscription);
                    }
                }
            });
        }

        $result['opportunities_generated'] = count($result['opportunities']);

        if ($result['total_expiring'] > 0) {
            $this->history->publish(new RenewalForecastEvent(
                forecastPeriod: $today->format('Y-m'),
                projectedRenewalsCount: $result['total_expiring'],
                projectedRenewalValue: (float) Money::round2($projected),
                renewalProbabilityRate: (float) $this->historicalRenewalRate($tenantId),
                currency: 'EUR',
                tenantId: $tenantId,
                companyId: $tenantId
            ));
        }

        return $result;
    }

    /**
     * Applies the transitions due on a date to a locked subscription, in order: pause end, effective cancellation,
     * term end (renewal or expiration), trial conversion.
     */
    public function applyDueTransitions(Subscription $subscription, CarbonImmutable $date, ?string $actorUserId): void
    {
        if ($subscription->status === 'paused' && $subscription->resume_on !== null && $subscription->resume_on->lte($date)) {
            $this->resume($subscription, $date, $actorUserId, 'Scheduled end of the pause');
        }

        if ($subscription->isCancelled()) {
            return;
        }

        $effective = $subscription->cancellation_effective_date;

        if ($effective !== null && $effective->lte($date) && $subscription->current_period_start !== null && $subscription->current_period_start->gte($effective)) {
            $this->close($subscription, 'cancelled', $effective, (string) ($subscription->cancellation_reason ?? 'Cancellation effective'), $actorUserId);

            return;
        }

        $termEnd = $subscription->term_end_date;

        if ($termEnd !== null && $termEnd->lt($date) && $subscription->current_period_start !== null && $subscription->current_period_start->gt($termEnd)) {
            $renewed = $this->autoRenewSubscription($subscription);

            if ($renewed->isCancelled()) {
                return;
            }
        }

        if ($subscription->status === 'trialing' && $subscription->trial_end_date !== null && $subscription->trial_end_date->lt($date)) {
            $this->convertTrial($subscription, $actorUserId);
        }
    }

    /**
     * Renews a locked subscription at the end of its term, or lets it expire when it does not renew by itself or is
     * cancelled at the end of the term. The price increase of the renewal multiplies the price factor of its items.
     */
    public function autoRenewSubscription(Subscription $subscription): Subscription
    {
        $termEnd = $subscription->term_end_date;

        if ($termEnd === null) {
            return $subscription;
        }

        $newTermStart = $termEnd->addDay();
        $effective = $subscription->cancellation_effective_date;

        if ($effective !== null && $effective->lte($newTermStart)) {
            $this->close($subscription, 'cancelled', $effective, (string) ($subscription->cancellation_reason ?? 'Cancelled at the end of the term'), null);

            return $subscription;
        }

        if (! $subscription->auto_renew) {
            $this->close($subscription, 'expired', $newTermStart, 'Term ended without renewal', null);

            return $subscription;
        }

        $months = max(1, (int) $subscription->contract_term_months);
        $newTermEnd = $newTermStart->addMonthsNoOverflow($months)->subDay();
        $increase = Money::of($subscription->getRawOriginal('renewal_price_increase_percent') ?? $subscription->plan?->getRawOriginal('renewal_price_increase_percent') ?? '0');
        $mrrBefore = Money::round2((string) $subscription->getRawOriginal('mrr'));

        if (Money::isPositive($increase)) {
            $multiplier = Money::add('1', Money::div($increase, '100'));

            foreach ($subscription->items as $item) {
                /** @var SubscriptionItem $item */
                $item->update(['price_factor' => Money::round(Money::mul((string) ($item->getRawOriginal('price_factor') ?? '1'), $multiplier), 8)]);
            }

            $subscription->unsetRelation('items');
        }

        $subscription->forceFill([
            'term_end_date' => $newTermEnd->toDateString(),
            'renewal_notified_at' => null,
        ])->save();

        [, $mrrAfter] = $this->history->refreshMrr($subscription, $subscription->status !== 'trialing');
        $this->history->version($subscription, 'renewal', $mrrBefore, $mrrAfter, $newTermStart, Money::isPositive($increase) ? "Automatic renewal with a {$increase}% price increase" : 'Automatic renewal', null);
        $this->history->audit('SUBSCRIPTION_AUTO_RENEWED', $subscription, ['term_end_date' => $termEnd->toDateString()], ['term_end_date' => $newTermEnd->toDateString(), 'price_increase_percent' => Money::round($increase, 4)]);
        $this->documentEvents->record($subscription, 'renewed', ['end_date' => $newTermEnd->toDateString()]);

        $this->history->publish(new SubscriptionRenewedEvent(
            subscriptionId: (string) $subscription->id,
            subscriptionNumber: (string) $subscription->subscription_number,
            newPeriodStart: $newTermStart->toDateString(),
            newPeriodEnd: $newTermEnd->toDateString(),
            renewalAmount: (float) Money::round2(Money::mul($mrrAfter, (string) $months)),
            tenantId: (string) $subscription->tenant_id,
            companyId: $this->history->companyOf($subscription)
        ));

        return $subscription;
    }

    /**
     * Renewal opportunity of a subscription that does not renew by itself (for the sales follow-up).
     *
     * @return array<string, mixed>
     */
    public function generateRenewalOpportunity(Subscription $subscription): array
    {
        return [
            'subscription_id' => (string) $subscription->id,
            'customer_id' => (string) $subscription->customer_id,
            'opportunity_type' => 'renewal',
            'title' => 'Contract renewal: '.$subscription->subscription_number,
            'estimated_value' => Money::round2(Money::mul((string) $subscription->getRawOriginal('mrr'), (string) max(1, (int) $subscription->contract_term_months))),
            'target_close_date' => $subscription->term_end_date?->toDateString(),
            'status' => 'identified',
        ];
    }

    /**
     * Ends the pause of a locked subscription: the periods that ended during the pause are skipped, not billed.
     */
    public function resume(Subscription $subscription, CarbonImmutable $date, ?string $actorUserId, string $reason): void
    {
        $plan = $subscription->plan;
        $interval = (string) ($plan->billing_interval ?? 'monthly');
        $count = (int) ($plan->interval_count ?? 1);
        $start = $subscription->current_period_start;

        while ($start !== null && $subscription->current_period_end !== null && $subscription->current_period_end->lt($date)) {
            $next = BillingCalendar::nextPeriodStart($subscription->anchor(), $start, $interval, $count, (string) $subscription->billing_alignment);
            [, $end] = BillingCalendar::period($subscription->anchor(), $next, $interval, $count, (string) $subscription->billing_alignment);
            $subscription->current_period_start = $next;
            $subscription->current_period_end = $end;
            $start = $next;
        }

        $subscription->forceFill(['status' => 'active', 'paused_at' => null, 'resume_on' => null, 'pause_reason' => null])->save();
        [$before, $after] = $this->history->refreshMrr($subscription);
        $this->history->version($subscription, 'resume', $before, $after, $date, $reason, $actorUserId);
        $this->history->audit('SUBSCRIPTION_RESUMED', $subscription, ['status' => 'paused'], ['status' => 'active']);
        $this->documentEvents->record($subscription, 'resumed');

        $this->history->publish(new SubscriptionReactivatedEvent(
            subscriptionId: (string) $subscription->id,
            subscriptionNumber: (string) $subscription->subscription_number,
            tenantId: (string) $subscription->tenant_id,
            companyId: $this->history->companyOf($subscription)
        ));
    }

    /**
     * Closes a locked subscription (cancelled or expired): no more billing, MRR to zero.
     */
    public function close(Subscription $subscription, string $status, CarbonImmutable $effectiveDate, string $reason, ?string $actorUserId): void
    {
        $before = $subscription->status;
        $subscription->forceFill([
            'status' => $status,
            'cancelled_at' => $status === 'cancelled' ? ($subscription->cancelled_at ?? now()) : $subscription->cancelled_at,
            'cancellation_effective_date' => $effectiveDate->toDateString(),
        ])->save();

        [$mrrBefore, $mrrAfter] = $this->history->refreshMrr($subscription, false);
        $this->history->version($subscription, $status === 'expired' ? 'expiration' : 'cancellation', $mrrBefore, $mrrAfter, $effectiveDate, $reason, $actorUserId);
        $this->history->audit($status === 'expired' ? 'SUBSCRIPTION_EXPIRED' : 'SUBSCRIPTION_CANCELLED', $subscription, ['status' => $before], ['status' => $status, 'reason' => $reason]);
        $this->documentEvents->record($subscription, $status === 'expired' ? 'expired' : 'cancelled', ['effective_date' => $effectiveDate->toDateString()]);

        $this->history->publish(new SubscriptionCancelledEvent(
            subscriptionId: (string) $subscription->id,
            subscriptionNumber: (string) $subscription->subscription_number,
            reason: $reason,
            cancelledAt: $effectiveDate->toIso8601String(),
            tenantId: (string) $subscription->tenant_id,
            companyId: $this->history->companyOf($subscription)
        ));
    }

    /**
     * Converts a locked trialing subscription to active once its trial is over.
     */
    public function convertTrial(Subscription $subscription, ?string $actorUserId): void
    {
        $subscription->forceFill(['status' => 'active'])->save();
        [$before, $after] = $this->history->refreshMrr($subscription);
        $this->history->version($subscription, 'trial_conversion', $before, $after, $subscription->trial_end_date?->addDay() ?? CarbonImmutable::today(), 'Trial ended', $actorUserId);
        $this->history->audit('SUBSCRIPTION_TRIAL_CONVERTED', $subscription, ['status' => 'trialing'], ['status' => 'active']);

        $this->history->publish(new SubscriptionActivatedEvent(
            subscriptionId: (string) $subscription->id,
            subscriptionNumber: (string) $subscription->subscription_number,
            customerId: (string) $subscription->customer_id,
            mrr: (float) $after,
            startDate: ($subscription->trial_end_date?->addDay() ?? CarbonImmutable::today())->toDateString(),
            tenantId: (string) $subscription->tenant_id,
            companyId: $this->history->companyOf($subscription)
        ));
    }

    /**
     * Records and mails the renewal notice of a subscription entering its notice window, once per term.
     */
    private function sendNoticeIfDue(Subscription $subscription, CarbonImmutable $today): bool
    {
        if ($subscription->renewal_notified_at !== null || $subscription->term_end_date === null || $subscription->cancel_at_period_end) {
            return false;
        }

        if ($subscription->term_end_date->gt($today->addDays((int) $subscription->renewal_notice_days))) {
            return false;
        }

        $this->documentEvents->record($subscription, 'renewal_notice', [
            'end_date' => $subscription->term_end_date->toDateString(),
            'auto_renew' => (bool) $subscription->auto_renew,
        ]);
        $subscription->forceFill(['renewal_notified_at' => now()])->save();

        if ($subscription->customer?->email !== null) {
            SendSubscriptionRenewalNotice::dispatch((string) $subscription->id)->afterCommit();
        }

        return true;
    }

    /**
     * Share of the terms that ended in the last twelve months and were renewed, as a decimal string (1 when none).
     */
    private function historicalRenewalRate(string $tenantId): string
    {
        $since = CarbonImmutable::today()->subYear()->toDateString();
        $counts = SubscriptionVersion::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('change_type', ['renewal', 'expiration'])
            ->whereDate('effective_date', '>=', $since)
            ->selectRaw('change_type, COUNT(*) AS total')
            ->groupBy('change_type')
            ->pluck('total', 'change_type');

        $renewed = (int) ($counts['renewal'] ?? 0);
        $ended = $renewed + (int) ($counts['expiration'] ?? 0);

        return $ended === 0 ? '1' : Money::round(Money::div((string) $renewed, (string) $ended), 4);
    }
}
