<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Enums;

/**
 * Lifecycle of a payment run (distinta di pagamento): draft, then the CFO approval, then the execution of the transfers.
 */
enum PaymentRunStatus: string
{
    case Draft = 'draft';
    case PendingCfoApproval = 'pending_cfo_approval';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Cancelled = 'cancelled';

    /**
     * Whether the items of the run are still reserved by it (and so unavailable to another run).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Draft, self::PendingCfoApproval, self::Authorized], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
