<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Enums;

/**
 * Lifecycle status of a Request for Quotation (Mod 045), preserving backward compatibility with legacy `issued`.
 */
enum RfqStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case BiddingOpen = 'bidding_open';
    case Issued = 'issued';
    case UnderEvaluation = 'under_evaluation';
    case Awarded = 'awarded';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isOpenForBidding(): bool
    {
        return in_array($this, [self::Published, self::BiddingOpen, self::Issued], true);
    }

    public function canBeEdited(): bool
    {
        return $this === self::Draft;
    }

    public function canBePublished(): bool
    {
        return $this === self::Draft;
    }

    public function canBeAwarded(): bool
    {
        return in_array($this, [self::Published, self::BiddingOpen, self::Issued, self::UnderEvaluation], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Published, self::BiddingOpen, self::Issued, self::UnderEvaluation, self::Expired], true);
    }

    public function canExtendDeadline(): bool
    {
        return in_array($this, [self::Draft, self::Published, self::BiddingOpen, self::Issued, self::UnderEvaluation, self::Expired], true);
    }
}
