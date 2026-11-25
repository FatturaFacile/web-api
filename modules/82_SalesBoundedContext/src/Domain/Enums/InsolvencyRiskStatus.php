<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum InsolvencyRiskStatus: string
{
    case Performing = 'performing';
    case Watchlist = 'watchlist';
    case CreditHold = 'credit_hold';
    case InsolventLegal = 'insolvent_legal';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
