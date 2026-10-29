<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class CreateLeadDTO
{
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
        public array $customFields = []
    ) {}
}
