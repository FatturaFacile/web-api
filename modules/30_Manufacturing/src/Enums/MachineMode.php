<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * Whether the operator attends the machine while it runs. Attended runs count as man time and machine time, unattended
 * (automatic) runs as machine time only.
 */
enum MachineMode: string
{
    case Attended = 'attended';
    case Unattended = 'unattended';
}
