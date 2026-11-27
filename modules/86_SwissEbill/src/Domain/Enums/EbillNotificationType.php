<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

/**
 * What a gateway notification (webhook or status poll) reports, independent of the provider wire format.
 */
enum EbillNotificationType: string
{
    case Delivered = 'delivered';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Settled = 'settled';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function targetStatus(): EbillTransmissionStatus
    {
        return match ($this) {
            self::Delivered => EbillTransmissionStatus::DeliveredToEbanking,
            self::Approved => EbillTransmissionStatus::ApprovedByDebtor,
            self::Rejected => EbillTransmissionStatus::RejectedByDebtor,
            self::Settled => EbillTransmissionStatus::PaidReconciled,
            self::Failed => EbillTransmissionStatus::FailedTechnical,
            self::Cancelled => EbillTransmissionStatus::Cancelled,
        };
    }
}
