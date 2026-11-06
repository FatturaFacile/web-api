<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

use DateTimeImmutable;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Calculation\EmployeeData;
use Modules\Payroll\Calculation\PayrollPreview;

/**
 * Italian monthly preview (the ItalianPayrollStrategy of the desktop): the annual tax spread evenly over the
 * installments, without conguaglio, family deductions and collective agreements.
 */
final class ItalianPayrollPreview
{
    public static function calculate(EmployeeData $employee, DateTimeImmutable $targetMonth, ItalianPayrollParameters $parameters = new ItalianPayrollParameters): PayrollPreview
    {
        $fte = Dec::isPositive($employee->fteRatio) ? $employee->fteRatio : '1';
        $installments = (string) ($employee->salaryInstallmentsPerYear > 0 ? $employee->salaryInstallmentsPerYear : 12);
        $annualGross = Dec::mul($employee->baseAnnualSalary, $fte);
        $monthlyGross = Dec::round(Dec::div($annualGross, $installments));

        $annualInps = Dec::add(
            Dec::mul($annualGross, $parameters->inpsEmployeeRate),
            Dec::mul(Dec::max('0', Dec::sub($annualGross, $parameters->inpsFirstBracket)), $parameters->inpsAdditionalRate),
        );
        $monthlyInps = Dec::round(Dec::div($annualInps, $installments));

        $taxableIncome = Dec::sub($annualGross, $annualInps);
        $deductions = Dec::add($parameters->workDeduction($taxableIncome), $parameters->taxWedgeDeduction($taxableIncome));
        $netIrpef = Dec::max('0', Dec::sub($parameters->grossIrpef($taxableIncome), $deductions));
        $monthlyIrpef = Dec::round(Dec::div($netIrpef, $installments));

        $surchargeRate = Dec::div(Dec::add($employee->regionalSurchargeRatePercent, $employee->municipalSurchargeRatePercent), '100');
        $monthlySurcharges = Dec::round(Dec::div(Dec::mul($taxableIncome, $surchargeRate), $installments));
        $monthlyBonus = Dec::round(Dec::div($parameters->taxWedgeBonus($taxableIncome), $installments));

        $totalDeductions = Dec::sum([$monthlyInps, $monthlyIrpef, $monthlySurcharges]);
        $netMonthly = Dec::add(Dec::sub($monthlyGross, $totalDeductions), $monthlyBonus);

        $employerInps = Dec::round(Dec::mul($monthlyGross, $parameters->indicativeEmployerInpsRate));
        $tfrMonthly = Dec::round(Dec::div(Dec::div($annualGross, $parameters->tfrDivisor), '12'));
        $employerTotalCost = Dec::sum([$monthlyGross, $employerInps, $tfrMonthly]);

        return new PayrollPreview(
            countryCode: 'IT',
            currency: 'EUR',
            grossMonthlySalary: $monthlyGross,
            totalDeductions: $totalDeductions,
            netMonthlySalary: $netMonthly,
            employerTotalCost: Dec::round($employerTotalCost),
            deductionBreakdown: [
                'Contributi INPS dipendente' => $monthlyInps,
                'IRPEF netta' => $monthlyIrpef,
                'Addizionali regionale e comunale' => $monthlySurcharges,
                'Somma non imponibile cuneo fiscale' => $monthlyBonus,
                'TFR maturato (costo azienda)' => $tfrMonthly,
            ],
            complianceNotes: 'Anteprima paga '.$targetMonth->format('m/Y')." con parametri {$parameters->year} (IRPEF 23/33/43%, detrazioni art. 13 TUIR, cuneo fiscale). Contributi datore INPS indicativi; esclusi CCNL, detrazioni familiari, conguaglio, LUL, UniEmens, F24, CU.",
        );
    }
}
