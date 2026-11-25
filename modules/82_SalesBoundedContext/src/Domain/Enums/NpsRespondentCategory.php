<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum NpsRespondentCategory: string
{
    case Promoter = 'promoter';
    case Passive = 'passive';
    case Detractor = 'detractor';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
