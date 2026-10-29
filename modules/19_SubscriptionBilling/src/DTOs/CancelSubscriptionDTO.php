<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

final readonly class CancelSubscriptionDTO
{
    public function __construct(
        public string $subscriptionId,
        public string $reason,
        public ?string $cancellationDate = null,
        public ?string $actorUserId = null
    ) {}
}
