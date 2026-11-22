<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum CenterType: string
{
    case COST_CENTER = 'COST_CENTER';
    case PROFIT_CENTER = 'PROFIT_CENTER';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
