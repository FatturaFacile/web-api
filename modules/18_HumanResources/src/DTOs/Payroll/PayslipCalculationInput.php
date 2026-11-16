<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

use DateTimeImmutable;

/**
 * Everything the pure Swiss payroll calculator needs for one employee and one monthly period.
 */
final readonly class PayslipCalculationInput
{
    /**
     * @param  int  $annualSalaryRappen  contractual full-time-equivalent annual salary
     * @param  int  $fteBasisPoints  employment rate in basis points (100% = 10000, 60% = 6000)
     * @param  list<EarningInput>  $earnings  variable inputs of the period
     */
    public function __construct(
        public string $employeeId,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
        public DateTimeImmutable $employmentStart,
        public ?DateTimeImmutable $employmentEnd,
        public ?DateTimeImmutable $birthDate,
        public int $annualSalaryRappen,
        public int $fteBasisPoints,
        public WageTypeData $baseSalaryWageType,
        public string $workCanton,
        public array $earnings = [],
        public bool $bvgInsured = true,
        public ?string $uvgClass = null,
        public bool $ktgInsured = false,
        public ?WithholdingTariffData $withholdingTariff = null,
    ) {}
}
