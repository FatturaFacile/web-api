<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a sales order (`sales_orders.status`), written by the sales API of the app, the e-commerce import and the
 * invoicing module. Credit, stock, fulfilment and billing progress live in their own columns; this is the life cycle.
 *
 * draft ─► confirmed ─► approved ─► in_production ─► in_fulfillment ─► shipped ─► completed
 *   │          │           │              │                 │
 *   └──────────┴───────────┴──► on_hold ──┴─────────────────┘   (on_hold goes back to confirmed once released)
 *   any open status ─► cancelled; draft, confirmed and on_hold ─► rejected (internal approval refused)
 *
 * A partial delivery puts the order in fulfilment, the last delivery ships it. Completed, cancelled and rejected orders
 * are final. The e-commerce import mirrors the status of the shop, which is the master of those orders, and does not go
 * through this state machine.
 */
enum SalesOrderStatus: string
{
    use DocumentStatusMachine;

    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Approved = 'approved';
    case OnHold = 'on_hold';
    case InProduction = 'in_production';
    case InFulfillment = 'in_fulfillment';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        $progress = [self::InProduction, self::InFulfillment, self::Shipped, self::Completed];

        return match ($this) {
            self::Draft => [self::Confirmed, self::Approved, self::OnHold, ...$progress, self::Cancelled, self::Rejected],
            self::Confirmed => [self::Approved, self::OnHold, ...$progress, self::Cancelled, self::Rejected],
            self::Approved => [self::Confirmed, self::OnHold, ...$progress, self::Cancelled],
            self::OnHold => [self::Confirmed, self::Cancelled, self::Rejected],
            self::InProduction => [self::OnHold, self::InFulfillment, self::Shipped, self::Completed, self::Cancelled],
            self::InFulfillment => [self::OnHold, self::Shipped, self::Completed, self::Cancelled],
            self::Shipped => [self::Completed],
            self::Completed, self::Cancelled, self::Rejected => [],
        };
    }

    /**
     * Statuses a user may give an order by hand when saving it, and that an e-commerce channel may map to.
     *
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return [self::Draft->value, self::Confirmed->value, self::InProduction->value, self::Shipped->value, self::Completed->value, self::Cancelled->value];
    }
}
