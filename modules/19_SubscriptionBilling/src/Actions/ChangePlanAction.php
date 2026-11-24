<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Actions;

use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription;

final readonly class ChangePlanAction
{
    public function __construct(
        private SubscriptionLifecycleServiceInterface $lifecycleService
    ) {}

    public function upgrade(UpgradeSubscriptionDTO $dto): Subscription
    {
        return $this->lifecycleService->upgradeSubscription($dto);
    }

    public function downgrade(DowngradeSubscriptionDTO $dto): Subscription
    {
        return $this->lifecycleService->downgradeSubscription($dto);
    }
}
