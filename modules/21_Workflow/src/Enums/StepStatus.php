<?php

declare(strict_types=1);

namespace Modules\Workflow\Enums;

/**
 * State of one approval step.
 */
enum StepStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
    case Escalated = 'escalated';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case ChangesRequested = 'changes_requested';
    case Reassigned = 'reassigned';
}
