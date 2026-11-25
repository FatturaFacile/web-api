<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum DeliveryPointType: string
{
    case Store = 'store';
    case Cedi = 'cedi';
    case Headquarters = 'headquarters';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
