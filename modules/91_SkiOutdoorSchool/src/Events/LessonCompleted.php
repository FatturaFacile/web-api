<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Events;

/**
 * A private lesson was taught: the commission of the instructor was recorded.
 */
final readonly class LessonCompleted
{
    public function __construct(
        public string $lessonId,
        public string $instructorId,
        public int $minutes,
    ) {}
}
