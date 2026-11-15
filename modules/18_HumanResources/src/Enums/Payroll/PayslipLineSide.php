<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums\Payroll;

enum PayslipLineSide: string
{
    case Employee = 'employee';
    case Employer = 'employer';
}
