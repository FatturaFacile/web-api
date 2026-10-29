<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\DTOs;

final readonly class CreatePlanDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public ?string $productId,
        public string $planCode,
        public string $name,
        public string $billingInterval = 'monthly',
        public int $intervalCount = 1,
        public string $pricingModel = 'flat_fee',
        public string $currency = 'EUR',
        public float $setupFee = 0.00,
        public float $basePrice = 0.00,
        public int $trialPeriodDays = 0,
        public int $minCommitmentCycles = 1,
        public ?string $description = null,
        public array $tiers = [],
        public array $features = [],
        public array $translations = [],
        public array $metadata = []
    ) {}
}
