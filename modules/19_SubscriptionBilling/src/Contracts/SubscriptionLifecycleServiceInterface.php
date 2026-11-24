<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\CarbonInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * Lifecycle of the subscriptions: creation, activation, pause/resume, prorated changes and cancellation.
 */
interface SubscriptionLifecycleServiceInterface
{
    /**
     * Creates a subscription (trialing or active).
     */
    public function createSubscription(CreateSubscriptionDTO $dto, ?string $actorUserId = null): Subscription;

    /**
     * Activates a subscription waiting for approval.
     */
    public function activateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription;

    /**
     * Suspends a subscription until it is reactivated.
     */
    public function suspendSubscription(string $subscriptionId, string $reason, ?string $actorUserId = null): Subscription;

    /**
     * Pauses a subscription, optionally until a resume date.
     */
    public function pauseSubscription(string $subscriptionId, string $reason, ?string $resumeOn, ?string $actorUserId = null): Subscription;

    /**
     * Resumes a paused or suspended subscription.
     */
    public function reactivateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription;

    /**
     * Prorated move to a higher plan.
     */
    public function upgradeSubscription(UpgradeSubscriptionDTO $dto): Subscription;

    /**
     * Prorated move to a lower plan.
     */
    public function downgradeSubscription(DowngradeSubscriptionDTO $dto): Subscription;

    /**
     * Prorated change of the quantity (seats) of the plan item.
     */
    public function changeQuantity(string $subscriptionId, string $quantity, ?string $effectiveDate, ?string $reason, ?string $actorUserId): Subscription;

    /**
     * Cancels immediately or at the end of the term.
     */
    public function cancelSubscription(CancelSubscriptionDTO $dto): Subscription;

    /**
     * Proration of a plan change against the billed period, without applying it.
     *
     * @return array{billed_period_start: ?string, billed_period_end: ?string, remaining_days: int, total_period_days: int, current_period_amount: string, new_period_amount: string, net_proration: string}
     */
    public function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan, CarbonInterface $effectiveDate, ?string $quantity = null): array;
}
