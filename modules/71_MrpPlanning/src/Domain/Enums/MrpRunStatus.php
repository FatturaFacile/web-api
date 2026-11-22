<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Lifecycle of an MRP run. */
enum MrpRunStatus: string
{
    case Pending = 'Pending';
    case Running = 'Running';
    case Completed = 'Completed';
    case Failed = 'Failed';
}
