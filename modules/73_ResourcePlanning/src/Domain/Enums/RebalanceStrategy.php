<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Enums;

enum RebalanceStrategy: string
{
    /** Moves the allocation in time, keeping its duration; may hand it to a colleague in the same gesture. */
    case Shift = 'shift';
    /** Hands the allocation to another employee. */
    case Reassign = 'reassign';
    /** Cuts the allocation in two at a date; the second part can move to a colleague or later in time. */
    case Split = 'split';
    /** Changes the dates or the hours per day of the allocation. */
    case Resize = 'resize';
}
