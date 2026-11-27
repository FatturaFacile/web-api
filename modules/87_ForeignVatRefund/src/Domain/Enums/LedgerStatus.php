<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Enums;

enum LedgerStatus: string
{
    case PendingReview = 'PENDING_REVIEW';
    case EligibleAccumulating = 'ELIGIBLE_ACCUMULATING';
    case IncludedInClaim = 'INCLUDED_IN_CLAIM';
    case Refunded = 'REFUNDED';
    case PartiallyRejected = 'PARTIALLY_REJECTED';
    case NonRecoverableExpensed = 'NON_RECOVERABLE_EXPENSED';

    /**
     * Items still editable: not yet part of a submitted claim.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::PendingReview, self::EligibleAccumulating], true);
    }
}
