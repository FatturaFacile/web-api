<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Carbon\Carbon;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Models\UsageAggregation;
use Modules\SubscriptionBilling\Models\UsageEvent;

interface UsageBillingServiceInterface
{
    public function recordUsage(RecordUsageDTO $dto): UsageEvent;

    public function aggregateUsage(string $subscriptionItemId, Carbon $periodStart, Carbon $periodEnd): UsageAggregation;

    public function rateUsage(UsageAggregation $aggregation): float;
}
