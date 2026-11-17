<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

/**
 * `HireCandidateAndCreateEmployeeCommand`: atomic conversion of a candidate with a signed offer into an employee.
 */
final readonly class HireCandidateDTO
{
    public function __construct(
        public string $applicationId,
        public ?string $hireDate = null,
        public ?string $workEmail = null,
        public ?string $managerId = null,
        public bool $startOnboarding = true,
        public ?string $actorUserId = null
    ) {}
}
