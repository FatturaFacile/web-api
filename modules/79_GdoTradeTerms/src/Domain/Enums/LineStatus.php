<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum LineStatus: string
{
    case Accrued = 'accrued';
    case Reversed = 'reversed';
    case Settled = 'settled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
