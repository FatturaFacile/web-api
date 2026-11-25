<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * The driving a trip adds to a driver: the time at the wheel (garage runs included), the breaks planned during it and its window.
 */
final readonly class TripPlan
{
    public function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public int $drivingMinutes,
        public int $plannedBreakMinutes = 0,
    ) {}
}
