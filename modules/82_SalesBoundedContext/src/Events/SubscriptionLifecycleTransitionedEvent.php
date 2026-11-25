<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class SubscriptionLifecycleTransitionedEvent
{
    use Dispatchable;

    public function __construct(
        public string $tenantId,
        public string $subscriptionId,
        public string $action,
        public string $newStatus,
        public string $mrrAmount,
    ) {}
}
