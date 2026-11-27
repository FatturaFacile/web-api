<?php

declare(strict_types=1);

namespace Modules\Esterometro\Domain\Enums;

enum SelfInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case PendingSend = 'PENDING_SEND';
    case SentToSdi = 'SENT_TO_SDI';
    case DeliveredRc = 'DELIVERED_RC';
    case RejectedNs = 'REJECTED_NS';
    case FailedTransmission = 'FAILED_TRANSMISSION';
    case Cancelled = 'CANCELLED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
