<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use DateTimeImmutable;
use Modules\HumanResources\Contracts\Payroll\SwissPayrollCalculatorInterface;
use Modules\HumanResources\DTOs\Payroll\EarningInput;
use Modules\HumanResources\DTOs\Payroll\PayslipCalculationInput;
use Modules\HumanResources\DTOs\Payroll\PayslipLineData;
use Modules\HumanResources\DTOs\Payroll\PayslipResult;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\Enums\Payroll\ContributionCode;
use Modules\HumanResources\Enums\Payroll\PayslipLineSide;
use Modules\HumanResources\Enums\Payroll\PayslipLineType;
use Modules\HumanResources\Enums\Payroll\WageTypeCategory;
use Modules\HumanResources\Exceptions\Payroll\PayrollCalculationException;

/**
 * Pure Swiss monthly payslip calculator. It reads every rate and threshold from the resolved RateSet and
 * never touches the database, the clock or the container.
 *
 * Calculation order: gross (sum of wage-type lines) -> AHV/IV/EO base -> ALV base capped at the ceiling plus
 * solidarity -> BVG coordinated salary -> UVG (BU/NBU) and KTG -> withholding tax base -> net.
 *
 * Simplifications (documented, to be refined in follow-up stories):
 * - Annual ceilings (ALV, UVG) are applied per month, pro-rated on the 30-day convention, not cumulatively
 *   over the year.
 * - BVG uses the contractual annual salary (FTE-adjusted) and only models the retirement credits by age band.
 * - KTG uses the uncapped UVG-subject salary as base.
 * - Withholding tax applies the monthly tariff to the period's taxable salary without converting partial
 *   months to a monthly equivalent (cantonal rules differ).
 */
final class SwissPayrollCalculator implements SwissPayrollCalculatorInterface
{
    public function calculate(PayslipCalculationInput $input, RateSet $rateSet): PayslipResult
    {
        $daysWorked = $this->daysWorked($input);

        /** @var list<PayslipLineData> $lines */
        $lines = [];
        /** @var list<EarningInput> $earnings */
        $earnings = [];

        $baseSalaryRappen = RoundingPolicy::divideRounded(
            $input->annualSalaryRappen * $input->fteBasisPoints * $daysWorked,
            12 * 10_000 * RoundingPolicy::DAYS_PER_MONTH,
        );

        if ($baseSalaryRappen > 0) {
            $earnings[] = new EarningInput($input->baseSalaryWageType, $baseSalaryRappen, $input->baseSalaryWageType->name);
        }

        foreach ($input->earnings as $earning) {
            if ($earning->amountRappen < 0) {
                throw PayrollCalculationException::invalid(
                    'Payroll input amounts must be positive; the wage type category defines the sign.',
                    ["employees.{$input->employeeId}.inputs" => ["Negative amount for wage type {$earning->wageType->code}."]],
                );
            }

            $earnings[] = $earning;
        }

        $grossRappen = 0;
        $ahvSubject = 0;
        $alvSubject = 0;
        $uvgSubject = 0;
        $qstSubject = 0;
        $benefitsInKind = 0;
        $expenses = 0;
        $netDeductions = 0;

        foreach ($earnings as $earning) {
            $wageType = $earning->wageType;
            $amount = $earning->amountRappen;
            $signedAmount = $wageType->category === WageTypeCategory::NetDeduction ? -$amount : $amount;

            $lines[] = new PayslipLineData(
                code: $wageType->code,
                label: $earning->label ?? $wageType->name,
                lineType: PayslipLineType::Earning,
                side: PayslipLineSide::Employee,
                baseRappen: $amount,
                ratePpm: null,
                amountRappen: $signedAmount,
                wageTypeId: $wageType->id,
            );

            if ($wageType->category === WageTypeCategory::Expense) {
                $expenses += $amount;
            }

            if ($wageType->category === WageTypeCategory::NetDeduction) {
                $netDeductions += $amount;
            }

            if (! $wageType->category->isPartOfGross()) {
                continue;
            }

            $grossRappen += $amount;

            if ($wageType->category === WageTypeCategory::BenefitInKind) {
                $benefitsInKind += $amount;
            }

            if ($wageType->isAhvSubject) {
                $ahvSubject += $amount;
            }

            if ($wageType->isAlvSubject) {
                $alvSubject += $amount;
            }

            if ($wageType->isUvgSubject) {
                $uvgSubject += $amount;
            }

            if ($wageType->isQstSubject) {
                $qstSubject += $amount;
            }
        }

        $isAhvContributor = $this->isAhvContributor($input, $rateSet);
        $isRetired = $this->isRetired($input, $rateSet);

        // 1. AHV/IV/EO (+ FAK and administration costs on the same base)
        $ahvBase = 0;

        if ($isAhvContributor) {
            $ahvBase = $isRetired
                ? max(0, $ahvSubject - $rateSet->retireeAllowanceMonthlyRappen)
                : $ahvSubject;
        }

        if ($ahvBase > 0) {
            $this->addContribution($lines, ContributionCode::AhvIvEo, PayslipLineSide::Employee, $ahvBase, $rateSet->ahvEmployeePpm);
            $this->addContribution($lines, ContributionCode::AhvIvEo, PayslipLineSide::Employer, $ahvBase, $rateSet->ahvEmployerPpm);
            $this->addContribution($lines, ContributionCode::AhvAdminCost, PayslipLineSide::Employer, $ahvBase, $rateSet->ahvAdminCostPpm);
            $this->addContribution($lines, ContributionCode::Fak, PayslipLineSide::Employer, $ahvBase, $rateSet->fakRateForCanton($input->workCanton));
        }

        // 2. ALV up to the (pro-rated monthly) ceiling, solidarity contribution above it
        $alvBase = 0;
        $alvSolidarityBase = 0;

        if ($isAhvContributor && ! $isRetired && $alvSubject > 0) {
            $monthlyCeiling = RoundingPolicy::annualToDays($rateSet->alvCeilingAnnualRappen, $daysWorked);
            $alvBase = min($alvSubject, $monthlyCeiling);
            $alvSolidarityBase = max(0, $alvSubject - $monthlyCeiling);

            $this->addContribution($lines, ContributionCode::Alv, PayslipLineSide::Employee, $alvBase, $rateSet->alvEmployeePpm);
            $this->addContribution($lines, ContributionCode::Alv, PayslipLineSide::Employer, $alvBase, $rateSet->alvEmployerPpm);

            if ($alvSolidarityBase > 0) {
                $this->addContribution($lines, ContributionCode::AlvSolidarity, PayslipLineSide::Employee, $alvSolidarityBase, $rateSet->alvSolidarityEmployeePpm);
                $this->addContribution($lines, ContributionCode::AlvSolidarity, PayslipLineSide::Employer, $alvSolidarityBase, $rateSet->alvSolidarityEmployerPpm);
            }
        }

        // 3. BVG retirement credits on the coordinated salary
        $bvgInsuredSalary = $this->addBvgContributions($lines, $input, $rateSet, $daysWorked, $isRetired);

        // 4. UVG occupational (employer) and non-occupational (employee) accident insurance
        $uvgBase = 0;

        if ($input->uvgClass !== null && $input->uvgClass !== '' && $uvgSubject > 0) {
            $uvgClass = $rateSet->uvgClass($input->uvgClass);

            if ($uvgClass === null) {
                throw PayrollCalculationException::invalid(
                    "UVG class {$input->uvgClass} is not defined in rate set {$rateSet->code}.",
                    ["employees.{$input->employeeId}.uvg_class" => ["Unknown UVG class {$input->uvgClass}."]],
                );
            }

            $uvgBase = min($uvgSubject, RoundingPolicy::annualToDays($rateSet->uvgMaxInsuredAnnualRappen, $daysWorked));

            $this->addContribution($lines, ContributionCode::UvgNbu, PayslipLineSide::Employee, $uvgBase, $uvgClass['nbu_ppm']);
            $this->addContribution($lines, ContributionCode::UvgBu, PayslipLineSide::Employer, $uvgBase, $uvgClass['bu_ppm']);
        }

        // 5. KTG daily sickness allowance insurance
        if ($input->ktgInsured && $uvgSubject > 0) {
            $this->addContribution($lines, ContributionCode::Ktg, PayslipLineSide::Employee, $uvgSubject, $rateSet->ktgEmployeePpm);
            $this->addContribution($lines, ContributionCode::Ktg, PayslipLineSide::Employer, $uvgSubject, $rateSet->ktgEmployerPpm);
        }

        // 6. Withholding tax (Quellensteuer)
        $qstBase = 0;
        $withholdingTax = 0;
        $tariff = $input->withholdingTariff;

        if ($tariff !== null) {
            $qstBase = $qstSubject;
            $withholdingTax = WithholdingTaxService::computeTax($tariff, $qstBase, $input->employeeId);
            $bracket = $tariff->bracketFor($qstBase);

            if ($withholdingTax > 0) {
                $lines[] = new PayslipLineData(
                    code: ContributionCode::WithholdingTax->value,
                    label: ContributionCode::WithholdingTax->label().' '.$tariff->canton.' '.$tariff->tariffCode,
                    lineType: PayslipLineType::WithholdingTax,
                    side: PayslipLineSide::Employee,
                    baseRappen: $qstBase,
                    ratePpm: $bracket['rate_ppm'] ?? null,
                    amountRappen: $withholdingTax,
                );
            }
        }

        // 7. Totals and net pay
        $employeeDeductions = 0;
        $employerContributions = 0;

        foreach ($lines as $line) {
            if ($line->isEmployeeDeduction()) {
                $employeeDeductions += $line->amountRappen;
            } elseif ($line->isEmployerContribution()) {
                $employerContributions += $line->amountRappen;
            }
        }

        $netRappen = $grossRappen - $employeeDeductions - $benefitsInKind + $expenses - $netDeductions;

        return new PayslipResult(
            employeeId: $input->employeeId,
            daysWorked: $daysWorked,
            lines: $lines,
            grossRappen: $grossRappen,
            ahvBaseRappen: $ahvBase,
            alvBaseRappen: $alvBase,
            alvSolidarityBaseRappen: $alvSolidarityBase,
            bvgInsuredSalaryRappen: $bvgInsuredSalary,
            uvgBaseRappen: $uvgBase,
            qstBaseRappen: $qstBase,
            withholdingTaxRappen: $withholdingTax,
            employeeDeductionsRappen: $employeeDeductions,
            employerContributionsRappen: $employerContributions,
            netRappen: $netRappen,
            qstTariffCode: $tariff?->tariffCode,
            qstCanton: $tariff?->canton,
        );
    }

    /**
     * Paid days of the period with the Swiss 30-day month convention: a full month is always 30 days, an
     * entry on the 16th gives 15 days, an exit on the 10th gives 10 days.
     */
    public function daysWorked(PayslipCalculationInput $input): int
    {
        $start = max($input->periodStart, $input->employmentStart);
        $end = $input->employmentEnd !== null ? min($input->periodEnd, $input->employmentEnd) : $input->periodEnd;

        if ($end < $start) {
            return 0;
        }

        $startDay = $start->format('Y-m-d') === $input->periodStart->format('Y-m-d')
            ? 1
            : min(RoundingPolicy::DAYS_PER_MONTH, (int) $start->format('j'));

        $endDay = $end->format('Y-m-d') === $input->periodEnd->format('Y-m-d')
            ? RoundingPolicy::DAYS_PER_MONTH
            : min(RoundingPolicy::DAYS_PER_MONTH, (int) $end->format('j'));

        return max(0, min(RoundingPolicy::DAYS_PER_MONTH, $endDay - $startDay + 1));
    }

    /**
     * @param  list<PayslipLineData>  $lines
     */
    private function addBvgContributions(array &$lines, PayslipCalculationInput $input, RateSet $rateSet, int $daysWorked, bool $isRetired): int
    {
        if (! $input->bvgInsured || $isRetired || $daysWorked === 0) {
            return 0;
        }

        $annualSalary = RoundingPolicy::divideRounded($input->annualSalaryRappen * $input->fteBasisPoints, 10_000);

        if ($annualSalary < $rateSet->bvgEntryThresholdRappen) {
            return 0;
        }

        if ($input->birthDate === null) {
            throw PayrollCalculationException::invalid(
                'The birth date is required to determine the BVG age band.',
                ["employees.{$input->employeeId}.birth_date" => ['The birth date is required for BVG insured employees.']],
            );
        }

        $coordinatedSalary = max(
            $rateSet->bvgMinCoordinatedSalaryRappen,
            min($annualSalary, $rateSet->bvgUpperLimitRappen) - $rateSet->bvgCoordinationDeductionRappen,
        );

        $bvgAge = (int) $input->periodStart->format('Y') - (int) $input->birthDate->format('Y');
        $creditRatePpm = $rateSet->bvgCreditRateForAge($bvgAge);

        if ($creditRatePpm === 0) {
            return $coordinatedSalary;
        }

        $monthlyCoordinated = RoundingPolicy::annualToDays($coordinatedSalary, $daysWorked);
        $employerRatePpm = RoundingPolicy::divideRounded($creditRatePpm * $rateSet->bvgEmployerSharePpm, Rappen::PPM_PER_UNIT);
        $employeeRatePpm = $creditRatePpm - $employerRatePpm;

        $this->addContribution($lines, ContributionCode::Bvg, PayslipLineSide::Employee, $monthlyCoordinated, $employeeRatePpm);
        $this->addContribution($lines, ContributionCode::Bvg, PayslipLineSide::Employer, $monthlyCoordinated, $employerRatePpm);

        return $coordinatedSalary;
    }

    /**
     * AHV contributions start on 1 January following the configured birthday (17th by law). Without a birth
     * date the employee is treated as a contributor.
     */
    private function isAhvContributor(PayslipCalculationInput $input, RateSet $rateSet): bool
    {
        if ($input->birthDate === null) {
            return true;
        }

        $ageInYear = (int) $input->periodStart->format('Y') - (int) $input->birthDate->format('Y');

        return $ageInYear > $rateSet->ahvContributionStartAge;
    }

    /**
     * Retirement applies from the month following the month in which the reference age is reached.
     */
    private function isRetired(PayslipCalculationInput $input, RateSet $rateSet): bool
    {
        if ($input->birthDate === null) {
            return false;
        }

        $referenceDate = $input->birthDate->modify("+{$rateSet->referenceAge} years");

        if (! $referenceDate instanceof DateTimeImmutable) {
            return false;
        }

        return $referenceDate->format('Y-m') < $input->periodStart->format('Y-m');
    }

    /**
     * @param  list<PayslipLineData>  $lines
     */
    private function addContribution(array &$lines, ContributionCode $code, PayslipLineSide $side, int $baseRappen, int $ratePpm): void
    {
        if ($ratePpm <= 0 || $baseRappen <= 0) {
            return;
        }

        $amount = RoundingPolicy::contribution($baseRappen, $ratePpm);

        if ($amount === 0) {
            return;
        }

        $lines[] = new PayslipLineData(
            code: $code->value,
            label: $code->label(),
            lineType: PayslipLineType::Contribution,
            side: $side,
            baseRappen: $baseRappen,
            ratePpm: $ratePpm,
            amountRappen: $amount,
        );
    }
}
