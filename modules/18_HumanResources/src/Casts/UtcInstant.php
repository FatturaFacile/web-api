<?php

declare(strict_types=1);

namespace Modules\HumanResources\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

/**
 * An instant stored as UTC. The framework date casts format a value in its own time zone, which would store local
 * time as if it were UTC; a string without an offset is local time.
 *
 * @implements CastsAttributes<CarbonImmutable, DateTimeInterface|string>
 */
final class UtcInstant implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : SwissWorkingTimeRules::parseLocal((string) $value);

        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
