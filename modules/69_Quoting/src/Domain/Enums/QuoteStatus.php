<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Enums;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Negotiating = 'negotiating';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Converted = 'converted';

    /** Once a revision left the draft states its payload is frozen. */
    public function isFrozen(): bool
    {
        return ! in_array($this, [self::Draft, self::PendingApproval], true);
    }

    /** The customer can still open, tune and sign the proposal. */
    public function isOpenForCustomer(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::Negotiating], true);
    }

    /** A new revision can be cut from it. */
    public function allowsNewRevision(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::Negotiating, self::Rejected, self::Expired], true);
    }
}
