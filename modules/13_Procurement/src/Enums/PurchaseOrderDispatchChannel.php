<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

/**
 * How a purchase order or a reminder reaches the supplier.
 */
enum PurchaseOrderDispatchChannel: string
{
    case Pdf = 'pdf';
    case Email = 'email';
    case Edi = 'edi';
    case Peppol = 'peppol';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
