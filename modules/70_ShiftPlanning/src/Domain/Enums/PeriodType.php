<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

enum PeriodType: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
}
