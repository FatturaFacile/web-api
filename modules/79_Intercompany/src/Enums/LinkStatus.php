<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum LinkStatus: string
{
    case Pending = 'pending';
    case Drafted = 'drafted';
    case Confirmed = 'confirmed';
    case Reversed = 'reversed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
