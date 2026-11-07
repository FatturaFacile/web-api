<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a quote (`quotes.status`), written by the sales API of the app and by the invoicing module.
 *
 * draft ──► pending_approval ──► approved ──► sent ──► accepted
 *   │              │                 │          │
 *   │              └──► rejected ◄───┴──────────┤
 *   └──► approved / sent / accepted /           └──► expired
 *        rejected / expired
 *
 * Internal approval is optional: a draft may be sent or accepted directly, or approved by a manager without being
 * submitted. A quote that waits for approval, though, cannot be sent, accepted or converted until it is approved. Converting a
 * quote into an order, a delivery note or an invoice leaves it accepted (the module used to write `converted`; the link
 * to the order is kept in `converted_to_order_id`). Rejected and expired quotes are final.
 */
enum QuoteStatus: string
{
    use DocumentStatusMachine;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * Values written before the vocabulary was unified, with the status they stand for now.
     *
     * @var array<string, string>
     */
    public const array LegacyValues = ['converted' => 'accepted'];

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Approved, self::Sent, self::Accepted, self::Rejected, self::Expired],
            self::PendingApproval => [self::Approved, self::Rejected],
            self::Approved => [self::Sent, self::Accepted, self::Rejected, self::Expired],
            self::Sent => [self::Accepted, self::Rejected, self::Expired],
            self::Accepted, self::Rejected, self::Expired => [],
        };
    }

    /**
     * Statuses a user may give a quote by hand when saving it; the approval statuses only come from the approval flow.
     *
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return [self::Draft->value, self::Sent->value, self::Accepted->value, self::Rejected->value, self::Expired->value];
    }
}
