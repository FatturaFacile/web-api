<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * A span of one day in minutes since midnight (end exclusive), built from "HH:MM" strings. Used by the planning board to find
 * overlapping lessons and blackouts.
 */
final readonly class TimeRange
{
    public function __construct(public int $startMinute, public int $endMinute)
    {
        if ($endMinute <= $startMinute) {
            throw new InvalidArgumentException('A time range must end after it starts.');
        }
    }

    public static function fromTimes(string $start, string $end): self
    {
        return new self(self::toMinutes($start), self::toMinutes($end));
    }

    public static function fromStart(string $start, int $minutes): self
    {
        $from = self::toMinutes($start);

        return new self($from, $from + $minutes);
    }

    public static function toMinutes(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw new InvalidArgumentException("Invalid time [{$time}], expected HH:MM.");
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }

    public static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    public function start(): string
    {
        return self::format($this->startMinute);
    }

    public function end(): string
    {
        return self::format($this->endMinute);
    }

    public function minutes(): int
    {
        return $this->endMinute - $this->startMinute;
    }

    public function overlaps(self $other): bool
    {
        return $this->startMinute < $other->endMinute && $other->startMinute < $this->endMinute;
    }

    public function contains(self $other): bool
    {
        return $this->startMinute <= $other->startMinute && $other->endMinute <= $this->endMinute;
    }
}
