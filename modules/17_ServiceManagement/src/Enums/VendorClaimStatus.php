<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

enum VendorClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Acknowledged = 'acknowledged';
    case ApprovedCreditNote = 'approved_credit_note';
    case ApprovedReplacement = 'approved_replacement';
    case Rejected = 'rejected';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Acknowledged],
            self::Acknowledged => [self::ApprovedCreditNote, self::ApprovedReplacement, self::Rejected],
            self::ApprovedCreditNote, self::ApprovedReplacement, self::Rejected => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isResolution(): bool
    {
        return in_array($this, [self::ApprovedCreditNote, self::ApprovedReplacement, self::Rejected], true);
    }
}
