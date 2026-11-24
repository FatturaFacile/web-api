<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * The instrument a time record comes from: the law leaves the employer free to choose it.
 */
enum TimeRecordSource: string
{
    case Badge = 'badge';
    case Terminal = 'terminal';
    case Web = 'web';
    case Mobile = 'mobile';
    case Spreadsheet = 'spreadsheet';
    case Paper = 'paper';
    case Manual = 'manual';
}
