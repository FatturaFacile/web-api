<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class TerminateEmployeeDTO
{
    public function __construct(
        public string $employeeId,
        public string $terminationDate,
        public string $reason,
        public bool $startOffboarding = true
    ) {}
}
