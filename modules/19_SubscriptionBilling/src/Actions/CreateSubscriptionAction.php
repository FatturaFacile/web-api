<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Actions;

use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\Models\Subscription;

final readonly class CreateSubscriptionAction
{
    public function __construct(
        private SubscriptionLifecycleServiceInterface $lifecycleService,
        private RevenueRecognitionServiceInterface $revRecService
    ) {}

    public function execute(CreateSubscriptionDTO $dto, ?string $actorUserId = null): Subscription
    {
        $subscription = $this->lifecycleService->createSubscription($dto, $actorUserId);

        // Auto-provision initial IFRS 15 Revenue Contract & POB Schedules
        $this->revRecService->createRevenueContract($subscription);

        return $subscription;
    }
}
