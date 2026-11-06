<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Ceased = 'ceased';
    case Rejected = 'rejected';
}
