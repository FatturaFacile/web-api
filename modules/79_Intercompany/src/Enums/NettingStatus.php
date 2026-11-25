<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum NettingStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
