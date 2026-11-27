<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Enums;

/**
 * Lifecycle of a refund claim: DRAFT -> READY_TO_SUBMIT -> SUBMITTED -> (ADDITIONAL_INFO_REQUESTED) -> APPROVED /
 * PARTIALLY_APPROVED / REJECTED -> REFUNDED.
 */
enum ClaimStatus: string
{
    case Draft = 'DRAFT';
    case ReadyToSubmit = 'READY_TO_SUBMIT';
    case Submitted = 'SUBMITTED';
    case AdditionalInfoRequested = 'ADDITIONAL_INFO_REQUESTED';
    case PartiallyApproved = 'PARTIALLY_APPROVED';
    case Approved = 'APPROVED';
    case Refunded = 'REFUNDED';
    case Rejected = 'REJECTED';

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::ReadyToSubmit],
            self::ReadyToSubmit => [self::Draft, self::Submitted],
            self::Submitted => [self::AdditionalInfoRequested, self::Approved, self::PartiallyApproved, self::Rejected, self::Refunded],
            self::AdditionalInfoRequested => [self::Submitted, self::Approved, self::PartiallyApproved, self::Rejected, self::Refunded],
            self::Approved, self::PartiallyApproved => [self::Refunded],
            self::Refunded, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /**
     * The items of the claim are locked once it was submitted.
     */
    public function locksItems(): bool
    {
        return ! in_array($this, [self::Draft, self::ReadyToSubmit], true);
    }

    public function isFinal(): bool
    {
        return $this === self::Refunded || $this === self::Rejected;
    }
}
