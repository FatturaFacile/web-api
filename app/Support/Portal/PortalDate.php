<?php

declare(strict_types=1);

namespace App\Support\Portal;

use Carbon\CarbonInterface;

/**
 * Presents the date attributes of the models in the shapes of the portal API. A value that is not a date (a missing
 * one) becomes null, so the resources stay correct whatever a column holds.
 */
final class PortalDate
{
    public static function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toISOString() : null;
    }

    public static function day(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : null;
    }

    public static function isPast(mixed $value): bool
    {
        return $value instanceof CarbonInterface && $value->isPast();
    }

    public static function isFuture(mixed $value): bool
    {
        return $value instanceof CarbonInterface && $value->isFuture();
    }
}
