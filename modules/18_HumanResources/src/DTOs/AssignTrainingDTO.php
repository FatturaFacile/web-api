<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class AssignTrainingDTO
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $courseId,
        public ?string $scheduledDate = null,
        public ?float $overrideCost = null,
        public ?string $notes = null
    ) {}
}
