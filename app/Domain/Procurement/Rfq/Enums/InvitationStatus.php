<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Enums;

/**
 * Invitation lifecycle status for an invited supplier (Mod 045 + legacy `invited` / `responded` compatibility).
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Invited = 'invited';
    case Viewed = 'viewed';
    case Declined = 'declined';
    case BidSubmitted = 'bid_submitted';
    case Responded = 'responded';
    case AwardedFull = 'awarded_full';
    case AwardedPartial = 'awarded_partial';
    case NotAwarded = 'not_awarded';

    public function canSubmitBid(): bool
    {
        return ! in_array($this, [self::Declined, self::AwardedFull, self::AwardedPartial, self::NotAwarded], true);
    }

    public function canDecline(): bool
    {
        return in_array($this, [self::Pending, self::Sent, self::Invited, self::Viewed], true);
    }

    public function hasSubmittedBid(): bool
    {
        return in_array($this, [self::BidSubmitted, self::Responded, self::AwardedFull, self::AwardedPartial, self::NotAwarded], true);
    }
}
