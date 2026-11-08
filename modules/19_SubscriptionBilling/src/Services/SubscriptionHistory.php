<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Carbon\CarbonInterface;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Modules\SubscriptionBilling\Events\ARRUpdatedEvent;
use Modules\SubscriptionBilling\Events\MRRUpdatedEvent;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionVersion;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;

/**
 * The trail of a subscription: versions with the MRR before and after each change (the source of the MRR movements
 * of the metrics), the audit ledger and the domain events, all inside the caller's transaction.
 */
final readonly class SubscriptionHistory
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private PricingCalculator $pricing,
    ) {}

    /**
     * Monthly recurring revenue of the subscription from its current items (usage excluded).
     */
    public function currentMrr(Subscription $subscription): string
    {
        $subscription->loadMissing(['plan.pricingTiers', 'items']);
        $plan = $subscription->plan;
        $periodAmount = Money::sum($subscription->items->map(fn ($item): string => $this->pricing->itemAmount($item, $plan)));

        return $this->pricing->monthly($periodAmount, (string) ($plan->billing_interval ?? 'monthly'), (int) ($plan->interval_count ?? 1));
    }

    /**
     * Recomputes and stores MRR and ARR; announces the change when there is one. Returns [old MRR, new MRR].
     *
     * @return array{0: string, 1: string}
     */
    public function refreshMrr(Subscription $subscription, bool $counted = true): array
    {
        $oldMrr = Money::round2($subscription->getRawOriginal('mrr') ?? '0');
        $newMrr = $counted ? $this->currentMrr($subscription) : '0.00';
        $oldArr = Money::round2($subscription->getRawOriginal('arr') ?? '0');
        $newArr = Money::round2(Money::mul($newMrr, '12'));

        $subscription->forceFill(['mrr' => $newMrr, 'arr' => $newArr])->save();

        if (Money::compare($oldMrr, $newMrr) !== 0) {
            $this->publish(new MRRUpdatedEvent(
                subscriptionId: (string) $subscription->id,
                oldMrr: (float) $oldMrr,
                newMrr: (float) $newMrr,
                mrrDelta: (float) Money::round2(Money::sub($newMrr, $oldMrr)),
                tenantId: (string) $subscription->tenant_id,
                companyId: $this->companyOf($subscription)
            ));
            $this->publish(new ARRUpdatedEvent(
                subscriptionId: (string) $subscription->id,
                oldArr: (float) $oldArr,
                newArr: (float) $newArr,
                arrDelta: (float) Money::round2(Money::sub($newArr, $oldArr)),
                tenantId: (string) $subscription->tenant_id,
                companyId: $this->companyOf($subscription)
            ));
        }

        return [$oldMrr, $newMrr];
    }

    /**
     * Appends a version (the snapshot of the subscription after the change).
     *
     * @param  array<string, mixed>  $extra  merged into the snapshot (proration documents, reasons)
     */
    public function version(Subscription $subscription, string $changeType, string $mrrBefore, string $mrrAfter, CarbonInterface $effectiveDate, ?string $reason, ?string $actorUserId, string $proration = '0', array $extra = []): SubscriptionVersion
    {
        $latest = (int) SubscriptionVersion::query()->where('subscription_id', $subscription->id)->max('version_number');

        return SubscriptionVersion::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'version_number' => $latest + 1,
            'effective_date' => $effectiveDate->toDateString(),
            'change_type' => $changeType,
            'mrr_before' => Money::round2($mrrBefore),
            'mrr_after' => Money::round2($mrrAfter),
            'mrr_delta' => Money::round2(Money::sub($mrrAfter, $mrrBefore)),
            'proration_amount' => Money::round2($proration),
            'snapshot_payload' => array_merge($subscription->attributesToArray(), $extra),
            'change_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'actor_user_id' => $actorUserId,
        ]);
    }

    /**
     * Records the change in the audit ledger of the module.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public function audit(string $action, Subscription $subscription, ?array $before, array $after): void
    {
        $this->auditLedger->record($action, $subscription, $before, $after, 'MODULE_19_SUB');
    }

    /**
     * Records the event in the outbox and dispatches it in process (General Ledger and other synchronous listeners).
     */
    public function publish(DomainEventInterface $event): void
    {
        $this->dispatcher->publishAndDispatch($event);
    }

    public function companyOf(Subscription $subscription): string
    {
        return (string) ($subscription->company_id ?? $subscription->tenant_id);
    }

    /**
     * Months a billing period of the plan of the subscription represents.
     */
    public function monthsPerPeriod(Subscription $subscription): int
    {
        return BillingCalendar::monthsPerPeriod((string) ($subscription->plan->billing_interval ?? 'monthly'), (int) ($subscription->plan->interval_count ?? 1));
    }
}
