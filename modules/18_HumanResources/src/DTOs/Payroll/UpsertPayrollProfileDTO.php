<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

final readonly class UpsertPayrollProfileDTO
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public string $employeeId,
        public ?string $ahvNumber,
        public string $workCanton,
        public ?string $residenceCanton = null,
        public string $residenceCountry = 'CH',
        public ?string $residenceMunicipalityBfs = null,
        public ?string $permitType = null,
        public string $civilStatus = 'single',
        public int $childrenCount = 0,
        public bool $churchTax = false,
        public ?string $qstTariffCode = null,
        public ?string $qstCanton = null,
        public bool $bvgInsured = true,
        public ?string $bvgPlanCode = null,
        public ?string $uvgClass = null,
        public bool $ktgInsured = false,
        public array $metadata = [],
    ) {}
}
