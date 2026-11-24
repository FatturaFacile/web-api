<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Enums;

/**
 * Lifecycle status of a Supplier Bid / Quotation.
 */
enum BidStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Revised = 'revised';
    case Withdrawn = 'withdrawn';
    case Received = 'received';
    case Awarded = 'awarded';
    case Rejected = 'rejected';

    public function isEvaluatable(): bool
    {
        return in_array($this, [self::Submitted, self::Revised, self::Received, self::Awarded, self::Rejected], true);
    }
}
