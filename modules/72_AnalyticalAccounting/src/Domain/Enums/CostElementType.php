<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum CostElementType: string
{
    case VARIABLE_DIRECT = 'VARIABLE_DIRECT';
    case FIXED_DIRECT = 'FIXED_DIRECT';
    case INDIRECT_AUXILIARY = 'INDIRECT_AUXILIARY';
    case REVENUE = 'REVENUE';
    case NON_OPERATING = 'NON_OPERATING';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
