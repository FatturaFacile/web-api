<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum CenterCategory: string
{
    case STRUCTURAL = 'STRUCTURAL';
    case AUXILIARY = 'AUXILIARY';
    case PRODUCTION = 'PRODUCTION';
    case LOGISTICS = 'LOGISTICS';
    case COMMERCIAL = 'COMMERCIAL';
    case PROFIT = 'PROFIT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
