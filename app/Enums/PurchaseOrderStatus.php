<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a purchase order (`purchase_orders.status`), written by the purchasing API of the app and by the procurement
 * module, which share the table.
 *
 * draft ─► pending_approval ─► approved ─► sent ─► partially_received ─► received ─► closed
 *   │             │   └─► rejected ─► draft          │                                  │
 *   └─► approved (no approval required)               └──────────────► closed ◄──────────┘ (closed reopens)
 *   any status before a receipt ─► cancelled
 *
 * Receiving is allowed from approved or sent. A closed order reopens to approved, or to partially received when goods
 * were already received. Cancelled orders are final.
 */
enum PurchaseOrderStatus: string
{
    use DocumentStatusMachine;

    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Draft => [self::PendingApproval, self::Approved, self::Sent, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Draft, self::Cancelled],
            self::Rejected => [self::Draft, self::Cancelled],
            self::Approved => [self::Sent, self::PartiallyReceived, self::Received, self::Closed, self::Cancelled],
            self::Sent => [self::PartiallyReceived, self::Received, self::Closed, self::Cancelled],
            self::PartiallyReceived => [self::Received, self::Closed],
            self::Received => [self::PartiallyReceived, self::Closed],
            self::Closed => [self::Approved, self::PartiallyReceived],
            self::Cancelled => [],
        };
    }

    /**
     * Statuses in which goods can be received against the order.
     *
     * @return list<string>
     */
    public static function receivableValues(): array
    {
        return [self::Approved->value, self::Sent->value, self::PartiallyReceived->value];
    }

    /**
     * Statuses that count as a commitment towards the supplier (spend, open orders).
     *
     * @return list<string>
     */
    public static function committedValues(): array
    {
        return [self::Approved->value, self::Sent->value, self::PartiallyReceived->value, self::Received->value, self::Closed->value];
    }
}
