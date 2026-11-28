<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum MovementKind: string
{
    case Nomadism = 'nomadism';
    case Sale = 'sale';
    case Purchase = 'purchase';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
