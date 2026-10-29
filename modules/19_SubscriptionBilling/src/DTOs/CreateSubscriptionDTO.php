<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

final readonly class CreateSubscriptionDTO
{
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $customerId,
        public string $planId,
        public string $startDate,
        public int $contractTermMonths = 12,
        public bool $autoRenew = true,
        public int $billingDayOfMonth = 1,
        public int $paymentTermsDays = 30,
        public string $paymentMethod = 'sepa_direct_debit',
        public ?string $bankAccountId = null,
        public array $items = [],
        public array $metadata = []
    ) {}
}
