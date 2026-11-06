<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Italian;

use Modules\Payroll\Calculation\PayrollDates;
use Modules\Payroll\Calculation\PayrollResult;

/**
 * Italian monthly payroll: salary and additional installments by collective agreement, INPS (with the 1% above the
 * first pensionable bracket), IRPEF with monthly withholding and year-end adjustment (conguaglio) in December or at
 * termination, regional and municipal surcharges of the previous year in 11 installments, tax wedge, employer INPS and
 * TFR.
 */
final class ItalianPayrollEngine
{
    /**
     * @return PayrollResult|null the payslip, or null when the employee was not employed in the month
     */
    public static function calculate(ItalianPayrollContext $context): ?PayrollResult
    {
        $employee = $context->employee;
        $first = PayrollDates::monthStart($context->year, $context->month);
        $last = PayrollDates::monthEnd($context->year, $context->month);

        if ($employee->hireDate > $last || ($employee->terminationDate !== null && $employee->terminationDate < $first)) {
            return null;
        }

        $calculation = new ItalianPayrollCalculation($context, $first, $last);
        $calculation->earnings();
        $calculation->contributions();
        $calculation->taxes();
        $calculation->employerCosts();

        return $calculation->build();
    }
}
