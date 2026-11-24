<?php

declare(strict_types=1);

namespace Modules\Workflow\DTOs;

use DateTimeInterface;

final readonly class CreateDelegationDTO
{
    public function __construct(
        public string $delegatorUserId,
        public string $delegateUserId,
        public DateTimeInterface $startsAt,
        public DateTimeInterface $endsAt,
        public string $scope = 'all',
        public ?string $reason = null,
        public ?string $tenantId = null,
        public ?string $companyId = null
    ) {}
}
