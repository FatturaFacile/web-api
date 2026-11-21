<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Backward schedules from the due date, forward from the first working day on or after today. */
enum SchedulingDirection: string
{
    case BackwardFromDueDate = 'BackwardFromDueDate';
    case ForwardFromToday = 'ForwardFromToday';
}
