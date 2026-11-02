<?php

declare(strict_types=1);

namespace App\Enums;

enum TimeEntryEventType: string
{
    case BreakStarted = 'break_start';
    case BreakEnded = 'break_end';
}
