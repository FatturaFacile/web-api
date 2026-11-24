<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * Outcome of an invoice matching run. BlockedDiscrepancy and PendingCreditNote keep the invoice out of the payment
 * schedule; every other status releases it.
 */
enum InvoiceMatchStatus: string
{
    case Matched = 'matched';
    case WithinTolerance = 'within_tolerance';
    case BlockedDiscrepancy = 'blocked_discrepancy';
    case PendingCreditNote = 'pending_credit_note';
    case OverrideApproved = 'override_approved';
    case Resolved = 'resolved';

    public function isBlocking(): bool
    {
        return $this === self::BlockedDiscrepancy || $this === self::PendingCreditNote;
    }

    public function isClearing(): bool
    {
        return ! $this->isBlocking();
    }

    /**
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return [self::BlockedDiscrepancy->value, self::PendingCreditNote->value];
    }

    /**
     * @return list<string>
     */
    public static function clearingValues(): array
    {
        return [self::Matched->value, self::WithinTolerance->value, self::OverrideApproved->value, self::Resolved->value];
    }
}
