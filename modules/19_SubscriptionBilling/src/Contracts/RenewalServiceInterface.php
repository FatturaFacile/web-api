<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Modules\SubscriptionBilling\Models\Subscription;

interface RenewalServiceInterface
{
    public function processExpiringSubscriptions(string $tenantId, int $lookaheadDays = 30): array;

    public function autoRenewSubscription(Subscription $subscription): Subscription;

    public function generateRenewalOpportunity(Subscription $subscription): array;
}
