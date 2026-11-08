<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\CarbonImmutable;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;
use Modules\SubscriptionBilling\Models\Subscription;

/**
 * Recurring billing of the subscriptions through the invoicing services, idempotent per period.
 */
interface RecurringBillingServiceInterface
{
    /**
     * Bills every due period of the tenant (or of one subscription) up to run date + lookahead.
     */
    public function executeBillingRun(ExecuteBillingRunDTO $dto): BillingRun;

    /**
     * Bills the due periods of one subscription up to the horizon.
     *
     * @return array{schedules: list<InvoiceSchedule>, error: ?string}
     */
    public function billSubscription(string $subscriptionId, CarbonImmutable $horizon, ?CarbonImmutable $runDate = null, ?BillingRun $run = null, ?string $actorUserId = null): array;

    /**
     * Lines and amount of the next period, without billing it.
     *
     * @return array{period_start: string, period_end: string, lines: list<array<string, string>>, net_total: string}
     */
    public function preview(Subscription $subscription): array;
}
