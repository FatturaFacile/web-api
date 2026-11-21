<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

enum PpeDeliveryStatus: string
{
    case DeliveredSigned = 'delivered_signed';
    case Returned = 'returned';
    case DamagedReplaced = 'damaged_replaced';
    case Expired = 'expired';

    /** Whether the employee currently holds the item. */
    public function isHeld(): bool
    {
        return $this === self::DeliveredSigned;
    }
}
