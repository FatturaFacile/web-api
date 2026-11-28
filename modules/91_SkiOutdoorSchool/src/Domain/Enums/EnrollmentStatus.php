<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * State of the enrolment of a student in a group course.
 */
enum EnrollmentStatus: string
{
    case ENROLLED = 'ENROLLED';
    case WITHDRAWN = 'WITHDRAWN';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
