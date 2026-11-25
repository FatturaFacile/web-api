<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum ContributionMode: string
{
    case PercentOfSales = 'percent_of_sales';
    case PerUnit = 'per_unit';
    case FixedAmount = 'fixed_amount';
    case PercentOfSellOut = 'percent_of_sell_out';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
