<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

enum RepairOutcome: string
{
    case Repaired = 'repaired';
    case ReturnedUnrepaired = 'returned_unrepaired';
    case Replaced = 'replaced';
}
