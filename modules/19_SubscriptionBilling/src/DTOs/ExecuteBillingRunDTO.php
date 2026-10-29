<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

final readonly class ExecuteBillingRunDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId = null,
        public ?string $runDate = null,
        public int $lookaheadDays = 0,
        public ?string $executedBy = null
    ) {}
}
