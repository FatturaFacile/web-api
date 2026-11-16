<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class CreateLeadDTO
{
    /**
     * @param  string|null  $duplicateStrategy  merge | notify | create (null = config default)
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $companyName,
        public ?string $email,
        public ?string $phone,
        public ?string $title = null,
        public ?string $website = null,
        public string $country = 'IT',
        public ?string $leadSourceCode = null,
        public ?string $campaignId = null,
        public ?string $assignedUserId = null,
        public ?float $estimatedBudget = null,
        public array $tags = [],
        public array $customFields = [],
        public ?string $vatNumber = null,
        public ?string $industry = null,
        public ?string $region = null,
        public ?string $productLine = null,
        public ?int $companySize = null,
        public ?float $estimatedRevenue = null,
        public int $emailOpens = 0,
        public int $catalogDownloads = 0,
        public int $siteVisits = 0,
        public ?string $leadSourceId = null,
        public ?string $duplicateStrategy = null,
    ) {}
}
