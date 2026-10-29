<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class PromoteEmployeeDTO
{
    public function __construct(
        public string $employeeId,
        public ?string $newPositionId,
        public ?string $newJobGradeId,
        public float $newBaseAnnualSalary,
        public ?string $effectiveDate = null,
        public string $reason = 'Merit and performance promotion'
    ) {}
}
