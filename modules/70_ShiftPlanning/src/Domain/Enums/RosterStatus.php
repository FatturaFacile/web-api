<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

/**
 * Life cycle of a roster period: managers edit a Draft freely, a Published roster is visible to the employees (open shifts
 * can be claimed, shifts swapped) and a Locked roster is the frozen baseline handed to payroll.
 */
enum RosterStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Locked = 'locked';

    public function isEditable(): bool
    {
        return $this !== self::Locked;
    }

    public function isVisibleToEmployees(): bool
    {
        return $this !== self::Draft;
    }
}
