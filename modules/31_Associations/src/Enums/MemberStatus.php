<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum MemberStatus: string
{
    case Candidate = 'candidate';
    case Active = 'active';
    case Inactive = 'inactive';
    case Ceased = 'ceased';
}
