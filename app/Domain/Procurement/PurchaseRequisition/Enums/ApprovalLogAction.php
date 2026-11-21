<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Action recorded in the immutable Purchase Requisition approval audit log.
 */
enum ApprovalLogAction: string
{
    case Submitted = 'submitted';
    case BudgetChecked = 'budget_checked';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Recalled = 'recalled';
}
