<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

final readonly class RecognizeRevenueDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId = null,
        public ?string $periodDate = null,
        public ?string $executedBy = null
    ) {}
}
