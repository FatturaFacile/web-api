<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * Time range of a slot inside one day, in minutes since midnight. The buffer (fitting and return of the harnesses) widens the range
 * of the slot being booked when it is compared with the slots already sold.
 */
final readonly class SlotWindow
{
    public function __construct(public int $startMinutes, public int $endMinutes)
    {
        if ($endMinutes <= $startMinutes) {
            throw new InvalidArgumentException('A slot must end after it starts.');
        }
    }

    public static function fromTimes(string $start, string $end): self
    {
        return new self(self::toMinutes($start), self::toMinutes($end));
    }

    public static function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', substr($time, 0, 5)));

        return $hours * 60 + $minutes;
    }

    public function durationMinutes(): int
    {
        return $this->endMinutes - $this->startMinutes;
    }

    /**
     * Whether `$other` falls inside [start - buffer, end + buffer] of this slot, so the harnesses of both are out at the same time.
     */
    public function overlapsWithBuffer(self $other, int $bufferMinutes): bool
    {
        return $other->startMinutes < $this->endMinutes + $bufferMinutes && $other->endMinutes > $this->startMinutes - $bufferMinutes;
    }
}
