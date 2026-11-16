<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts\Payroll;

use Modules\HumanResources\DTOs\Payroll\PayslipCalculationInput;
use Modules\HumanResources\DTOs\Payroll\PayslipResult;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\Exceptions\Payroll\PayrollCalculationException;

/**
 * Pure, side-effect-free Swiss payslip calculation.
 */
interface SwissPayrollCalculatorInterface
{
    /**
     * @throws PayrollCalculationException when the input cannot be calculated with the given rate set
     */
    public function calculate(PayslipCalculationInput $input, RateSet $rateSet): PayslipResult;
}
