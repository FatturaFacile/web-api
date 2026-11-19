<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

/**
 * Lifecycle of an electronic invoice envelope. Suffixes follow the SDI receipt codes (RC, MC, NS, NE, DT, AT).
 */
enum FpaStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case ValidationFailed = 'validation_failed';
    case Signed = 'signed';
    case Queued = 'queued';
    case Transmitted = 'transmitted';
    case Delivered = 'delivered';
    case Undelivered = 'undelivered';
    case Rejected = 'rejected';
    case PaAccepted = 'pa_accepted';
    case PaRejected = 'pa_rejected';
    case DeadlinePassed = 'deadline_passed';
    case TransmittedAttested = 'transmitted_attested';
    case InboundReceived = 'inbound_received';
    case InboundProcessed = 'inbound_processed';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Validated, self::ValidationFailed],
            self::ValidationFailed => [self::Validated, self::ValidationFailed],
            self::Validated => [self::Signed, self::Queued, self::ValidationFailed],
            self::Signed => [self::Queued],
            self::Queued => [self::Transmitted],
            self::Transmitted => [
                self::Delivered, self::Undelivered, self::Rejected, self::TransmittedAttested,
                self::PaAccepted, self::PaRejected, self::DeadlinePassed,
            ],
            self::Undelivered => [
                self::Delivered, self::TransmittedAttested, self::PaAccepted, self::PaRejected, self::DeadlinePassed,
            ],
            self::Delivered => [self::PaAccepted, self::PaRejected, self::DeadlinePassed],
            self::InboundReceived => [self::InboundProcessed],
            default => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
