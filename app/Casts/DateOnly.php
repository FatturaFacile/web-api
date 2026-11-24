<?php

declare(strict_types=1);

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date stored as `Y-m-d`. The stock `date` cast writes `Y-m-d 00:00:00`: PostgreSQL and MySQL drop the time
 * part in a date column, SQLite keeps the text, so equality, unique indexes and `<=` against a plain date string
 * missed the rows of that day. This cast writes the same plain date on every engine and reads it as a Carbon date.
 *
 * @implements CastsAttributes<Carbon|null, mixed>
 */
final class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value))->toDateString();
    }
}
