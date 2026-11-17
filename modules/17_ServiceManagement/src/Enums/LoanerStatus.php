<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

enum LoanerStatus: string
{
    case Issued = 'issued';
    case Returned = 'returned';
    case Lost = 'lost';
}
