<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum ApplicationStatus: string
{
    case Submitted = 'submitted';
    case PendingBoard = 'pending_board';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
