<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

/**
 * What a shift type stands for in the plan.
 */
enum ShiftCategory: string
{
    case Working = 'working';
    case Absence = 'absence';
    case OnCall = 'on_call';
}
