<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class HireEmployeeDTO
{
    /**
     * @param  array<string, mixed>  $emergencyContact
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $firstName,
        public string $lastName,
        public string $workEmail,
        public float $baseAnnualSalary,
        public ?string $hireDate = null,
        public ?string $employeeNumber = null,
        public ?string $userId = null,
        public ?string $departmentId = null,
        public ?string $teamId = null,
        public ?string $businessUnitId = null,
        public ?string $costCenterId = null,
        public ?string $positionId = null,
        public ?string $jobGradeId = null,
        public ?string $managerId = null,
        public string $employmentType = 'full_time',
        public string $contractType = 'permanent',
        public string $collectiveAgreement = 'CCNL Metalmeccanico',
        public float $weeklyHours = 40.0,
        public int $probationMonths = 3,
        public string $currency = 'EUR',
        public ?string $taxCode = null,
        public string $nationality = 'IT',
        public array $emergencyContact = [],
        public array $metadata = []
    ) {}
}
