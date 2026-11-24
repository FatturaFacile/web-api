<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use DateTimeImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollPreview;

/**
 * Swiss monthly preview (the SwissPayrollStrategy of the desktop): AVS/AI/IPG, AD with ceiling, LPP/BVG on the
 * coordinated salary with statutory age credits or the pension fund rate, LAINF NBU, IGM and withholding tax from the
 * employee's rate. It is an estimate, not a Swissdec-certified payroll.
 */
final class SwissPayrollPreview
{
    public static function calculate(EmployeeData $employee, DateTimeImmutable $targetMonth, SwissPayrollParameters $parameters = new SwissPayrollParameters): PayrollPreview
    {
        $fte = Dec::isPositive($employee->fteRatio) ? $employee->fteRatio : '1';
        $installments = (string) ($employee->salaryInstallmentsPerYear > 0 ? $employee->salaryInstallmentsPerYear : 12);
        $annualGross = Dec::mul($employee->baseAnnualSalary, $fte);
        $monthlyGross = Dec::round(Dec::div($annualGross, $installments));
        $age = $employee->dateOfBirth === null ? null : (int) $targetMonth->format('Y') - (int) $employee->dateOfBirth->format('Y');
        $belowContributionAge = $age !== null && $age <= 17;
        $retired = $age !== null && $age > $parameters->referenceAge;
        $notes = [];

        $ahvBase = $belowContributionAge ? '0' : $monthlyGross;

        if ($retired && ! $employee->waiveRetireeAllowance) {
            $ahvBase = Dec::max('0', Dec::sub($monthlyGross, $parameters->ahvRetireeMonthlyAllowance));
        }

        $ahv = Dec::round(Dec::mul($ahvBase, $parameters->ahvEmployeeRate));
        $cappedMonthly = Dec::div(Dec::min($annualGross, $parameters->alvMaxAnnualSalary), $installments);
        $alvBase = $belowContributionAge || $retired ? '0' : $cappedMonthly;
        $alv = Dec::round(Dec::mul($alvBase, $parameters->alvEmployeeRate));
        $bvg = self::bvgEmployee($employee, $annualGross, $age, $notes, $parameters);
        $uvgBase = $cappedMonthly;
        $nbuDue = Dec::isZero($employee->weeklyHours)
            || Dec::cmp($employee->weeklyHours, $parameters->nbuMinimumWeeklyHours) >= 0;
        $nbu = $nbuDue ? Dec::round(Dec::div(Dec::mul($uvgBase, $employee->nonOccupationalAccidentRatePercent), '100')) : '0';
        $ktg = Dec::round(Dec::div(Dec::mul($monthlyGross, $employee->dailySicknessRatePercent), '100'));
        $qst = '0';

        if ($employee->isWithholdingTaxSubject) {
            if ($employee->withholdingTaxRatePercent !== null) {
                $qst = Dec::round(Dec::div(Dec::mul($monthlyGross, $employee->withholdingTaxRatePercent), '100'));
            } else {
                $notes[] = 'Imposta alla fonte: aliquota della tariffa cantonale mancante, ritenuta non calcolata.';
            }
        }

        if ($age === null) {
            $notes[] = 'Data di nascita mancante: regole di età AVS/AD e accrediti LPP non applicabili con precisione.';
        }

        $totalDeductions = Dec::sum([$ahv, $alv, $bvg, $nbu, $ktg, $qst]);
        $employerBu = Dec::round(Dec::div(Dec::mul($uvgBase, $employee->occupationalAccidentRatePercent), '100'));
        $employerFak = Dec::round(Dec::div(Dec::mul($ahvBase, $employee->familyAllowanceFundRatePercent), '100'));
        $employerTotalCost = Dec::sum([$monthlyGross, $ahv, $alv, $bvg, $employerBu, $employerFak]);

        array_unshift($notes, 'Anteprima paga '.$targetMonth->format('m/Y')." con parametri federali {$parameters->year}. Non certificata Swissdec; trasmissione ELM non implementata.");

        return new PayrollPreview(
            countryCode: 'CH',
            currency: 'CHF',
            grossMonthlySalary: $monthlyGross,
            totalDeductions: $totalDeductions,
            netMonthlySalary: Dec::sub($monthlyGross, $totalDeductions),
            employerTotalCost: Dec::round($employerTotalCost),
            deductionBreakdown: [
                'AVS/AI/IPG' => $ahv,
                'AD' => $alv,
                'LPP/BVG' => $bvg,
                'LAINF NBU' => $nbu,
                'IGM/KTG' => $ktg,
                'Imposta alla fonte' => $qst,
            ],
            complianceNotes: implode(' ', $notes),
        );
    }

    /**
     * @param  list<string>  $notes
     */
    private static function bvgEmployee(EmployeeData $employee, string $annualGross, ?int $age, array &$notes, SwissPayrollParameters $parameters): string
    {
        $coordinated = SwissPayrollRules::bvgCoordinatedSalary($annualGross, $parameters);

        if (Dec::isZero($coordinated)) {
            return '0';
        }

        if ($employee->pensionFundEmployeeRatePercent !== null) {
            return Dec::round(Dec::div(Dec::div(Dec::mul($coordinated, $employee->pensionFundEmployeeRatePercent), '100'), '12'));
        }

        if ($age === null) {
            return '0';
        }

        $totalCredit = self::bvgAgeCreditRate($age, $parameters);

        if (Dec::isZero($totalCredit) && $age < 25) {
            $notes[] = 'LPP: sotto i 25 anni solo contributi di rischio, definiti dal regolamento della cassa pensione.';
        }

        return Dec::round(Dec::div(Dec::div(Dec::mul($coordinated, $totalCredit), '2'), '12'));
    }

    private static function bvgAgeCreditRate(int $bvgAge, SwissPayrollParameters $parameters): string
    {
        return match (true) {
            $bvgAge < 25 => '0',
            $bvgAge <= 34 => '0.07',
            $bvgAge <= 44 => '0.10',
            $bvgAge <= 54 => '0.15',
            $bvgAge <= $parameters->referenceAge => '0.18',
            default => '0',
        };
    }
}
