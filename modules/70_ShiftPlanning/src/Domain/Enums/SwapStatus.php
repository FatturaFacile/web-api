<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Enums;

/**
 * Two-step approval of a shift swap: the colleague accepts first, then the manager decides.
 */
enum SwapStatus: string
{
    case PendingPeerAcceptance = 'pending_peer_acceptance';
    case PendingManagerApproval = 'pending_manager_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isOpen(): bool
    {
        return $this === self::PendingPeerAcceptance || $this === self::PendingManagerApproval;
    }
}
