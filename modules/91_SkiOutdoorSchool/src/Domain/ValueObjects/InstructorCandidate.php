<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\ValueObjects;

/**
 * An instructor as the fair rotation sees him or her for one lesson: what is taught and spoken, whether the slot is free and how many
 * hours were already assigned in the rotation period.
 */
final readonly class InstructorCandidate
{
    /**
     * @param  list<string>  $disciplines  Discipline values
     * @param  list<string>  $languages  upper case ISO codes
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $disciplines,
        public array $languages,
        public bool $available,
        public float $hoursInPeriod,
        public ?string $lastAssignedAt = null,
        public ?string $unavailableReason = null,
    ) {}
}
