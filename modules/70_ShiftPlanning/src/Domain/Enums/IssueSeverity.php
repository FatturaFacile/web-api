<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

enum IssueSeverity: string
{
    /** The assignment cannot be saved: a statutory rule, a leave, a missing mandatory certificate or a clash. */
    case BlockingError = 'blocking_error';

    /** The assignment is saved and shown with a badge: the planner decides. */
    case Warning = 'warning';
}
