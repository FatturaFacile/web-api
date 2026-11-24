<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

/**
 * Move to a higher plan (and optionally a new quantity), prorated from the effective date.
 */
final readonly class UpgradeSubscriptionDTO
{
    public function __construct(
        public string $subscriptionId,
        public string $newPlanId,
        public ?string $effectiveDate = null,
        public ?string $changeReason = null,
        public ?string $actorUserId = null,
        public ?string $quantity = null,
    ) {}
}
