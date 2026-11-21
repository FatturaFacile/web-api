<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Contracts;

use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\OneClickTokenBundle;
use App\Models\User;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Localized Email & Push notification gateway for Mod 044 Purchase Requisition events.
 */
interface RequisitionNotificationGatewayInterface
{
    /**
     * Notifies the step's authorized approvers via localized Email and Push (with single-use 1-click tokens).
     *
     * @param  list<OneClickTokenBundle>  $tokens
     */
    public function notifyApproversStepAssigned(
        PurchaseRequisition $requisition,
        WorkflowInstanceStep $step,
        array $tokens,
    ): void;

    /**
     * Notifies the requester that submission was blocked due to hard-block budget overage.
     */
    public function notifyRequesterBudgetBlocked(
        PurchaseRequisition $requisition,
        BudgetCheckResult $budgetCheck,
    ): void;

    /**
     * Notifies the requester that submission triggered a budget warning and proceeded to approval.
     */
    public function notifyRequesterBudgetWarning(
        PurchaseRequisition $requisition,
        BudgetCheckResult $budgetCheck,
    ): void;

    /**
     * Notifies the requester that the requisition received final approval.
     */
    public function notifyRequesterApproved(
        PurchaseRequisition $requisition,
        User $approver,
        ?string $comment = null,
    ): void;

    /**
     * Notifies the requester that the requisition was rejected at an approval tier.
     */
    public function notifyRequesterRejected(
        PurchaseRequisition $requisition,
        User $approver,
        int $stepLevel,
        string $reason,
    ): void;
}
