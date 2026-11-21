<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Lifecycle status of a Purchase Requisition (Mod 044), aligned with legacy procurement statuses.
 */
enum RequisitionStatus: string
{
    case Draft = 'draft';
    case PendingBudgetCheck = 'pending_budget_check';
    case BudgetExceededBlocked = 'budget_exceeded_blocked';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case PartiallyConverted = 'partially_converted';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    /**
     * Whether header or line items may still be edited.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::BudgetExceededBlocked], true);
    }

    /**
     * Whether the requisition may be submitted for budget checking and approval.
     */
    public function isSubmittable(): bool
    {
        return in_array($this, [self::Draft, self::BudgetExceededBlocked], true);
    }

    /**
     * Whether approved lines may be converted into downstream RFQ (Mod 045) or PO (Mod 046) documents.
     */
    public function isConvertible(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyConverted], true);
    }

    /**
     * Whether the requisition may be soft-deleted directly.
     */
    public function isDeletable(): bool
    {
        return $this === self::Draft;
    }
}
