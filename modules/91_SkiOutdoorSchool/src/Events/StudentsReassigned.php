<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Events;

/**
 * Students were moved between level groups after the evaluation.
 */
final readonly class StudentsReassigned
{
    public function __construct(
        public array $enrollmentIds,
        public string $reason,
    ) {}
}
