<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum CourseKind: string
{
    case Recurring = 'recurring';
    case Event = 'event';
    case BigEvent = 'big_event';
}
