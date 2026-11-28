<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum ProductKind: string
{
    case Honey = 'honey';
    case Beeswax = 'beeswax';
    case Propolis = 'propolis';
    case RoyalJelly = 'royal_jelly';
    case LiveBees = 'live_bees';
    case NonFood = 'non_food';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
