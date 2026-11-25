<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum MatchStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Disputed = 'disputed';
    case WrittenOff = 'written_off';
    case Adjusted = 'adjusted';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
