<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum SaleChannel: string
{
    case Direct = 'direct';
    case Wholesale = 'wholesale';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
