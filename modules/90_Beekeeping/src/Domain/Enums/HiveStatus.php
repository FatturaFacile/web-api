<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum HiveStatus: string
{
    case Active = 'active';
    case Orphaned = 'orphaned';
    case QueenlessCell = 'queenless_cell';
    case United = 'united';
    case Dead = 'dead';
    case Sold = 'sold';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
