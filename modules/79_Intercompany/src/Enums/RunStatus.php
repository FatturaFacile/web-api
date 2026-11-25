<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum RunStatus: string
{
    case Draft = 'draft';
    case Calculating = 'calculating';
    case Review = 'review';
    case Locked = 'locked';
    case Posted = 'posted';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
