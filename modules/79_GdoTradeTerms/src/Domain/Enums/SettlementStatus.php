<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum SettlementStatus: string
{
    case Proposed = 'proposed';
    case Disputed = 'disputed';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
