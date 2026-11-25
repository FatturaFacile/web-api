<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum SettlementLineStatus: string
{
    case Open = 'open';
    case Settled = 'settled';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
