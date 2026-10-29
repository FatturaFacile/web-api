<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

final readonly class RecordUsageDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $subscriptionId,
        public string $subscriptionItemId,
        public string $metricCode,
        public float $quantity,
        public ?string $eventTimestamp = null,
        public ?string $idempotencyKey = null,
        public array $metadata = []
    ) {}
}
