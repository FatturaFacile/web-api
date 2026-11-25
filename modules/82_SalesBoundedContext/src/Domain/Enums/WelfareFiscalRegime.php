<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\Enums;

enum WelfareFiscalRegime: string
{
    case ItArt51Tuir = 'it_art51_tuir';
    case ChRekaRailLunch = 'ch_reka_rail_lunch';
    case B2bCralDiscount = 'b2b_cral_discount';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
