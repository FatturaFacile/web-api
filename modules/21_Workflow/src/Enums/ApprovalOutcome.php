<?php

declare(strict_types=1);

namespace Modules\Workflow\Enums;

use Modules\Workflow\Contracts\ApprovableDocument;

/**
 * How an approval ended, as told to the consumer document (see {@see ApprovableDocument}).
 */
enum ApprovalOutcome: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Withdrawn = 'withdrawn';
    case ChangesRequested = 'changes_requested';
    case Expired = 'expired';

    /**
     * Maps the string outcome the engine uses internally to the enum; unknown values count as cancelled.
     */
    public static function fromEngine(string $outcome): self
    {
        return self::tryFrom($outcome) ?? self::Cancelled;
    }

    /**
     * Whether the document may move on (confirmed / executive).
     */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }
}
