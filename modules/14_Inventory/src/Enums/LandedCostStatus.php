<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum LandedCostStatus: string
{
    case Draft = 'draft';
    case Applied = 'applied';
    case Reversed = 'reversed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
