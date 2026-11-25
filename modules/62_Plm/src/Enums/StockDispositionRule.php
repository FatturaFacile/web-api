<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

enum StockDispositionRule: string
{
    case UseUpEsaurimentoScorte = 'UseUp_EsaurimentoScorte';
    case ReworkInHouse = 'ReworkInHouse';
    case ScrapImmediately = 'ScrapImmediately';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
