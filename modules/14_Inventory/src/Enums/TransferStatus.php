<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * Transfer order state machine:
 * draft -> approved -> in_transit (shipped) -> partially_received* -> received | discrepancy_pending -> closed,
 * with cancellation allowed before shipping.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case DiscrepancyPending = 'discrepancy_pending';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Approved, self::Cancelled],
            self::Approved => [self::InTransit, self::Cancelled],
            self::InTransit => [self::PartiallyReceived, self::Received, self::DiscrepancyPending],
            self::PartiallyReceived => [self::PartiallyReceived, self::Received, self::DiscrepancyPending],
            self::Received, self::DiscrepancyPending => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Stock still sits on the virtual in-transit location. */
    public function holdsStockInTransit(): bool
    {
        return in_array($this, [self::InTransit, self::PartiallyReceived, self::DiscrepancyPending], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
