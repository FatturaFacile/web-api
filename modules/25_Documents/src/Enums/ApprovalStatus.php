<?php

declare(strict_types=1);

namespace Modules\Documents\Enums;

/**
 * Where a document approval request stands.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
