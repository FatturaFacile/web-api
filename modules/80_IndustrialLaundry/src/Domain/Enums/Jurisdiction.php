<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum Jurisdiction: string
{
    case It = 'IT';
    case Ch = 'CH';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
