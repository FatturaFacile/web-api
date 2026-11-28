<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Events;

/**
 * A private lesson was booked.
 */
final readonly class LessonBooked
{
    public function __construct(
        public string $lessonId,
        public ?string $instructorId,
        public string $source,
    ) {}
}
