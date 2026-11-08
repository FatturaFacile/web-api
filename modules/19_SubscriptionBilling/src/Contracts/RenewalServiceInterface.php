<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Modules\SubscriptionBilling\Models\Subscription;

/**
 * Renewal notices, automatic renewals with price increases, expirations and the other time-driven transitions.
 */
interface RenewalServiceInterface
{
    /**
     * Daily processing of the subscriptions of a tenant.
     *
     * @return array{total_expiring: int, auto_renewed_count: int, expired_count: int, notices_sent: int, opportunities_generated: int, opportunities: list<array<string, mixed>>}
     */
    public function processExpiringSubscriptions(string $tenantId, int $lookaheadDays = 30): array;

    /**
     * Renews (or expires) a locked subscription at the end of its term.
     */
    public function autoRenewSubscription(Subscription $subscription): Subscription;

    /**
     * Renewal opportunity of a subscription that does not renew by itself.
     *
     * @return array<string, mixed>
     */
    public function generateRenewalOpportunity(Subscription $subscription): array;
}
