<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\DocumentStatusMachine;

/**
 * Status of a sales return (RMA, `sales_returns.status`).
 *
 * requested ──► approved ──► received ──► credited
 *     │             │
 *     ├──► rejected └──► cancelled
 *     └──► cancelled
 *
 * A return is requested by the customer (or by sales on its behalf), authorised by a sales manager, received in the
 * warehouse (resellable goods go back into stock) and finally credited with a credit note on the original invoice. A
 * return received without an invoice to credit (goods delivered but never invoiced) stays received. Credited, rejected
 * and cancelled returns are final.
 */
enum SalesReturnStatus: string
{
    use DocumentStatusMachine;

    case Requested = 'requested';
    case Approved = 'approved';
    case Received = 'received';
    case Credited = 'credited';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved => [self::Received, self::Cancelled],
            self::Received => [self::Credited],
            self::Credited, self::Rejected, self::Cancelled => [],
        };
    }
}
