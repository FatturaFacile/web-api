<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

/**
 * Input of a billing run: the periods starting up to run date + lookahead days are billed, for the whole tenant or
 * for one subscription.
 */
final readonly class ExecuteBillingRunDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId = null,
        public ?string $runDate = null,
        public int $lookaheadDays = 0,
        public ?string $executedBy = null,
        public ?string $subscriptionId = null,
    ) {}
}
