<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum WarehouseSubtype: string
{
    case TypeC = 'Type_C';
    case TypeD = 'Type_D';
    case TypeE = 'Type_E';
    case Private = 'Private';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
