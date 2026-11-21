<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * A shift as the compliance rules see it: who, when, how long, how much break. Instants are absolute; the strategies convert
 * them to the local time of the jurisdiction.
 */
final readonly class PlannedShift
{
    public function __construct(
        public ?string $assignmentId,
        public string $employeeId,
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
        public int $breakMinutes = 0,
        public bool $isOnCall = false,
        public bool $nightSurchargeApplicable = true,
    ) {}

    /**
     * Length of the shift without the unpaid break.
     */
    public function workedMinutes(): int
    {
        return max(0, (int) round(($this->endAt->getTimestamp() - $this->startAt->getTimestamp()) / 60) - $this->breakMinutes);
    }

    public function overlaps(self $other): bool
    {
        return $this->startAt < $other->endAt && $other->startAt < $this->endAt;
    }

    public function localDate(string $timezone): CarbonImmutable
    {
        return $this->startAt->setTimezone($timezone)->startOfDay();
    }
}
