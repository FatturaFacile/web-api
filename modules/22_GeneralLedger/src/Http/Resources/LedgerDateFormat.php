<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Resources;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates and instants of the ledger payloads in one format: Y-m-d for dates, ISO 8601 for instants, null when empty.
 */
final class LedgerDateFormat
{
    public static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->toDateString() : CarbonImmutable::parse((string) $value)->toDateString();
    }

    public static function instant(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : CarbonImmutable::parse((string) $value)->toIso8601String();
    }
}
