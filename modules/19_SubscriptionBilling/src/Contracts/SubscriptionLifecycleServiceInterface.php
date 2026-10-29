<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\Carbon;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

interface SubscriptionLifecycleServiceInterface
{
    public function createSubscription(CreateSubscriptionDTO $dto, ?string $actorUserId = null): Subscription;

    public function activateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription;

    public function suspendSubscription(string $subscriptionId, string $reason, ?string $actorUserId = null): Subscription;

    public function reactivateSubscription(string $subscriptionId, ?string $actorUserId = null): Subscription;

    public function upgradeSubscription(UpgradeSubscriptionDTO $dto): Subscription;

    public function downgradeSubscription(DowngradeSubscriptionDTO $dto): Subscription;

    public function cancelSubscription(CancelSubscriptionDTO $dto): Subscription;

    public function calculateProration(Subscription $subscription, SubscriptionPlan $newPlan, Carbon $effectiveDate): array;
}
