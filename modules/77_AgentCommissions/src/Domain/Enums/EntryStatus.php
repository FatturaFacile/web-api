<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum EntryStatus: string
{
    case PendingCollection = 'pending_collection';
    case AwaitingReconciliation = 'awaiting_reconciliation';
    case PartiallyEligible = 'partially_eligible';
    case Eligible = 'eligible';
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
