<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum DeliveryNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Delivered = 'delivered';
    case PartiallyInvoiced = 'partially_invoiced';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function invoiceable(): array
    {
        return [self::Issued->value, self::Delivered->value, self::PartiallyInvoiced->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
