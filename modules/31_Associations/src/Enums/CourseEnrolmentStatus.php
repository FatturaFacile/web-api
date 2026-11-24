<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum CourseEnrolmentStatus: string
{
    case Enrolled = 'enrolled';
    case Waitlisted = 'waitlisted';
    case Withdrawn = 'withdrawn';
}
