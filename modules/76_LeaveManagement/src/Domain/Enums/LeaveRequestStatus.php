<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\Enums;

enum LeaveRequestStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function isActiveOnCalendar(): bool
    {
        return $this === self::PENDING_APPROVAL || $this === self::APPROVED;
    }
}
