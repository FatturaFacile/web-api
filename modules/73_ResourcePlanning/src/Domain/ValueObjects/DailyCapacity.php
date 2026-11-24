<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

/**
 * The capacity of one employee on one day. Net hours are the contractual hours minus holidays and approved leave.
 */
final readonly class DailyCapacity
{
    public function __construct(
        public string $date,
        public float $contractualHours,
        public float $holidayHours,
        public float $leaveHours,
        public bool $isFullHoliday,
    ) {}

    public function netHours(): float
    {
        return max(0.0, $this->contractualHours - $this->holidayHours - $this->leaveHours);
    }

    /**
     * Hours booked on a day count only where the employee would work: a weekend, a day off of a part-timer or a public
     * holiday carries no booking. A day of leave does count, so that booking someone on leave shows up as overload.
     */
    public function isBookable(): bool
    {
        return $this->contractualHours > 0.0 && ! $this->isFullHoliday;
    }
}
