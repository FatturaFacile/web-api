<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * How a participant took part in a meeting.
 */
enum AttendanceStatus: string
{
    case Invited = 'invited';
    case Present = 'present';
    case Online = 'online';
    case Absent = 'absent';
}
