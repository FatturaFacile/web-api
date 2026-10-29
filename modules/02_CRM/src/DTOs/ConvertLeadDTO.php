<?php

declare(strict_types=1);

namespace Modules\CRM\DTOs;

final readonly class ConvertLeadDTO
{
    public function __construct(
        public ?string $existingAccountId = null,
        public ?string $newAccountName = null,
        public ?string $accountTier = 'standard',
        public ?string $contactRole = 'decision_maker',
        public bool $createOpportunity = true,
        public ?string $opportunityName = null,
        public ?float $opportunityAmount = null,
        public ?string $expectedCloseDate = null,
        public ?string $ownerUserId = null
    ) {}
}
