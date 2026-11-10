<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation;

use DateTimeImmutable;

/**
 * The employee data the engines read, in the shape of the desktop HrEmployee.
 */
final readonly class EmployeeData
{
    public function __construct(
        public DateTimeImmutable $hireDate,
        public string $id = '',
        public string $fullName = '',
        public ?DateTimeImmutable $terminationDate = null,
        public ?DateTimeImmutable $dateOfBirth = null,
        public ?string $gender = null,
        public string $baseAnnualSalary = '0',
        public string $fteRatio = '1',
        public int $salaryInstallmentsPerYear = 12,
        public bool $isFixedTermContract = false,
        public string $payType = 'monthly',
        public string $hourlyWage = '0',
        public string $weeklyHours = '42',
        public int $holidayWeeks = 4,
        public ?string $workCanton = null,
        public ?string $residenceCountry = 'CH',
        public ?string $residenceCanton = null,
        public bool $isWithholdingTaxSubject = false,
        public ?string $withholdingTaxCode = null,
        public ?string $withholdingTaxRatePercent = null,
        public string $nonOccupationalAccidentRatePercent = '0',
        public string $occupationalAccidentRatePercent = '0',
        public string $dailySicknessRatePercent = '0',
        public string $familyAllowanceFundRatePercent = '0',
        public ?string $pensionFundEmployeeRatePercent = null,
        public string $regionalSurchargeRatePercent = '0',
        public string $municipalSurchargeRatePercent = '0',
        public bool $waiveRetireeAllowance = false,
        public bool $extrapolateQstToFullTime = false,
        public string $otherEmploymentFtePercent = '0',
        public ?string $qstRateDeterminingPercent = null,
        public ?string $frontierRegime = null,
        public bool $frontierApply80PercentBasis = false,
    ) {}
}
