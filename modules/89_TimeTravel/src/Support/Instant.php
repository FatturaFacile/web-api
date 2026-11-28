<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Every time of the event store is a UTC instant with microseconds. This is the only place that converts between the three
 * representations: Carbon, the database string and the canonical form that enters the hash.
 */
final class Instant
{
    public static function from(string|DateTimeInterface $value): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        // A string without a zone (database values) is UTC.
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /**
     * A request value: a date alone means "to the end of that day", a date with time is taken as it is.
     */
    public static function fromInput(string $value): CarbonImmutable
    {
        $instant = self::from($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1 ? $instant->endOfDay() : $instant;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    /**
     * Posting dates are valid from the start of the day.
     */
    public static function startOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse(substr($date, 0, 10), 'UTC')->startOfDay();
    }

    public static function db(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s.u');
    }

    public static function iso(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
