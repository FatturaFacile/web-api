<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum LandedCostAllocationKey: string
{
    case Value = 'value';
    case Weight = 'weight';
    case Volume = 'volume';
    case Quantity = 'quantity';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }
}
