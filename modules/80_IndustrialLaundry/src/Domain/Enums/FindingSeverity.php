<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum FindingSeverity: string
{
    case Warning = 'warning';
    case Blocking = 'blocking';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
