<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum SettlementKind: string
{
    case YearEnd = 'year_end';
    case Quarterly = 'quarterly';
    case Campaign = 'campaign';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
