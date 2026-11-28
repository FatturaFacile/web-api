<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Lifecycle of a weekly group course.
 */
enum CourseStatus: string
{
    case PLANNED = 'PLANNED';
    case OPEN = 'OPEN';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function acceptsEnrolments(): bool
    {
        return in_array($this, [self::PLANNED, self::OPEN, self::RUNNING], true);
    }
}
