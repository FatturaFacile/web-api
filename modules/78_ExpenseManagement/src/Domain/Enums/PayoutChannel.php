<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

enum PayoutChannel: string
{
    case Pain001 = 'pain001';
    case Payroll = 'payroll';
}
