<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

enum EstimateApprovalStatus: string
{
    case NotRequired = 'not_required';
    case AutoApprovedUnderThreshold = 'auto_approved_under_threshold';
    case PendingCustomerApproval = 'pending_customer_approval';
    case Approved = 'approved';
    case DeclinedReturnUnrepaired = 'declined_return_unrepaired';

    /**
     * Public decision label exposed to the customer ("approved" / "declined" / null).
     */
    public function publicDecision(): ?string
    {
        return match ($this) {
            self::Approved, self::AutoApprovedUnderThreshold => 'approved',
            self::DeclinedReturnUnrepaired => 'declined',
            default => null,
        };
    }
}
