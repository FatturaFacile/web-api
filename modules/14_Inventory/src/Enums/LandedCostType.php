<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum LandedCostType: string
{
    case Freight = 'freight';
    case CustomsDuty = 'customs_duty';
    case Insurance = 'insurance';
    case Brokerage = 'brokerage';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
