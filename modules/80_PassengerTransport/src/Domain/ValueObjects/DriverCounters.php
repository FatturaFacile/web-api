<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Domain\ValueObjects;

use DateTimeImmutable;

/**
 * Rolling driving and rest counters of a driver at the start of a trip, in minutes.
 */
final readonly class DriverCounters
{
    public function __construct(
        public int $dailyDrivingMinutesUsed = 0,
        public int $extended10hDaysUsedThisWeek = 0,
        public int $continuousDrivingMinutesSinceBreak = 0,
        public int $weeklyDrivingMinutesUsed = 0,
        public int $biweeklyDrivingMinutesUsed = 0,
        public int $lastDailyRestDurationMinutes = 660,
        public int $reduced9hRestsUsedBetweenWeeklyRests = 0,
        public ?DateTimeImmutable $lastWeeklyRestEndedAt = null,
    ) {}
}
