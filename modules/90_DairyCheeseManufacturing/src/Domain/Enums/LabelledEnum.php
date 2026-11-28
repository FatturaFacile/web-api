<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Domain\Enums;

/**
 * Shared helpers of the string-backed enums of the module: raw values and the {value, label_key} pairs of the lookups.
 */
trait LabelledEnum
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @param  string  $name  the enum name of the API contract (session, tank_status, cheese_type, ...)
     * @return list<array{value: string, label_key: string}>
     */
    public static function options(string $name): array
    {
        $prefix = 'DAIRY.ENUM.'.strtoupper($name).'.';

        return array_map(static fn (self $case): array => ['value' => $case->value, 'label_key' => $prefix.strtoupper($case->value)], self::cases());
    }
}
