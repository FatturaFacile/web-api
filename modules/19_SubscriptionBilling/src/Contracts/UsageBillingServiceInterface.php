<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\CarbonInterface;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Models\UsageEvent;

/**
 * Metered usage records, their aggregation and rating per billing period.
 */
interface UsageBillingServiceInterface
{
    /**
     * Records a usage event, idempotent on its key.
     */
    public function recordUsage(RecordUsageDTO $dto): UsageEvent;

    /**
     * Aggregates and rates the usage of a metered item in a period.
     */
    public function aggregateUsage(string $subscriptionItemId, CarbonInterface $periodStart, CarbonInterface $periodEnd): UsageAggregation;

    /**
     * Rated amount of an aggregation, as a decimal string.
     */
    public function rateUsage(UsageAggregation $aggregation): string;
}
