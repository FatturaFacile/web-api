<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class CreateOpportunityDTO
{
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public string $accountId,
        public ?string $contactId,
        public string $name,
        public float $amount,
        public string $currency = 'EUR',
        public ?string $stageCode = 'discovery',
        public ?string $forecastCategory = 'pipeline',
        public ?string $expectedCloseDate = null,
        public ?string $ownerUserId = null,
        public ?string $campaignId = null,
        public ?string $leadId = null,
        public float $discountPercentage = 0.00,
        public array $lineItems = [],
        public array $tags = [],
        public array $metadata = []
    ) {}
}
