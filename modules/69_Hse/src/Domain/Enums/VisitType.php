<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

enum VisitType: string
{
    case PreventiveHiring = 'preventive_hiring';
    case Periodic = 'periodic';
    case ReturnFromLongSickLeave = 'return_from_long_sick_leave';
    case EmployeeRequest = 'employee_request';
    case JobChange = 'job_change';
}
