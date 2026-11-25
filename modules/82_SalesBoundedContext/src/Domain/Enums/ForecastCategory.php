<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum ForecastCategory: string
{
    case Pipeline = 'pipeline';
    case BestCase = 'best_case';
    case Commit = 'commit';
    case ClosedWon = 'closed_won';
    case Omitted = 'omitted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
