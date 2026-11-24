<?php

declare(strict_types=1);

namespace Modules\Payroll\Calculation\Swiss;

use Modules\Payroll\Calculation\PayrollResult;

/**
 * Swiss monthly payroll calculation driven by the wage type catalog: every line contributes to the bases
 * (AHV, ALV, UVG, KTG, BVG, withholding tax) according to the flags of its wage type.
 */
final class SwissPayrollEngine
{
    /**
     * @return PayrollResult|null the result, or null when the employee is not employed in the month
     */
    public static function calculate(SwissPayrollContext $context): ?PayrollResult
    {
        $employee = $context->employee;
        $paidDays = SwissPayrollRules::paidDays($context->year, $context->month, $employee->hireDate, $employee->terminationDate);

        if ($paidDays <= 0) {
            return null;
        }

        $calculation = new SwissPayrollCalculation($context, (string) $paidDays);
        $calculation->addEarnings();
        $calculation->addInputs();
        $calculation->addFamilyAllowances();
        $calculation->computeBases();
        $calculation->addContributions();
        $calculation->addWithholdingTax();

        return $calculation->build();
    }
}
