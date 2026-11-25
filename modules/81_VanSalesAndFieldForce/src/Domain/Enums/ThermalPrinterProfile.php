<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Domain\Enums;

enum ThermalPrinterProfile: string
{
    case EscPos80mm = 'escpos_80mm';
    case Zpl104mm = 'zpl_104mm';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
