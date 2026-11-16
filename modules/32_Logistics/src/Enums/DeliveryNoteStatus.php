<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum DeliveryNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case PartiallyInvoiced = 'partially_invoiced';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    /** Legacy `shipping_status` column value kept in sync with the status machine. */
    public function shippingStatus(): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Issued => 'ready',
            self::InTransit => 'dispatched',
            self::Delivered, self::PartiallyInvoiced, self::Invoiced => 'delivered',
            self::Cancelled => 'cancelled',
        };
    }

    /**
     * @return list<string>
     */
    public static function invoiceable(): array
    {
        return [self::Issued->value, self::InTransit->value, self::Delivered->value, self::PartiallyInvoiced->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
