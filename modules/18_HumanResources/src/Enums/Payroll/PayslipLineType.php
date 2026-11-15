<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums\Payroll;

enum PayslipLineType: string
{
    case Earning = 'earning';
    case Contribution = 'contribution';
    case WithholdingTax = 'withholding_tax';
}
