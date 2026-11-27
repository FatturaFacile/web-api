<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Enums;

/**
 * Lifecycle of one eBill transmission. The allowed moves are the single source of truth of the state machine
 * (EbillTransmission::applyTransition refuses everything else).
 */
enum EbillTransmissionStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Transmitted = 'transmitted';
    case DeliveredToEbanking = 'delivered_to_ebanking';
    case ApprovedByDebtor = 'approved_by_debtor';
    case RejectedByDebtor = 'rejected_by_debtor';
    case FailedTechnical = 'failed_technical';
    case PaidReconciled = 'paid_reconciled';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Queued, self::FailedTechnical, self::Cancelled],
            self::Queued => [self::Transmitted, self::FailedTechnical, self::Cancelled],
            // A bank may report approval without a delivery receipt, and the camt.054 credit can arrive before the webhook.
            // The network validates asynchronously: a technical rejection can still follow the acceptance.
            self::Transmitted => [self::DeliveredToEbanking, self::ApprovedByDebtor, self::RejectedByDebtor, self::PaidReconciled, self::FailedTechnical, self::Cancelled],
            self::DeliveredToEbanking => [self::ApprovedByDebtor, self::RejectedByDebtor, self::PaidReconciled, self::Cancelled],
            self::ApprovedByDebtor => [self::RejectedByDebtor, self::PaidReconciled],
            self::FailedTechnical => [self::Queued, self::Cancelled],
            self::RejectedByDebtor, self::PaidReconciled, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * States in which the debtor may still see the invoice, so a settlement or a recall is meaningful.
     */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Transmitted, self::DeliveredToEbanking, self::ApprovedByDebtor], true);
    }

    /**
     * Position in the happy path, used to ignore out-of-order gateway notifications (a delivery receipt after an approval).
     */
    public function rank(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Queued => 1,
            self::FailedTechnical => 1,
            self::Transmitted => 2,
            self::DeliveredToEbanking => 3,
            self::ApprovedByDebtor => 4,
            self::RejectedByDebtor, self::PaidReconciled, self::Cancelled => 5,
        };
    }

    /**
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isInFlight()));
    }
}
