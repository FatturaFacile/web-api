<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

/**
 * One metered usage record of a subscription item; the quantity is a decimal string.
 */
final readonly class RecordUsageDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $subscriptionId,
        public string $subscriptionItemId,
        public string $metricCode,
        public string $quantity,
        public ?string $eventTimestamp = null,
        public ?string $idempotencyKey = null,
        public array $metadata = []
    ) {}
}
