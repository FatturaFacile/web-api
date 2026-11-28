<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\Enums;

/**
 * Lifecycle of a private lesson.
 */
enum LessonStatus: string
{
    case REQUESTED = 'REQUESTED';
    case CONFIRMED = 'CONFIRMED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case NO_SHOW = 'NO_SHOW';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** A lesson in one of these statuses occupies the time of its instructor. */
    public function occupiesInstructor(): bool
    {
        return in_array($this, [self::REQUESTED, self::CONFIRMED, self::COMPLETED], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::REQUESTED, self::CONFIRMED], true);
    }
}
