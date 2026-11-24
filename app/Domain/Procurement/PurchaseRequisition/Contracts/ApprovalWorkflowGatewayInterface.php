<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Contracts;

use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\ApprovalTierSpec;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\OneClickTokenBundle;
use App\Models\User;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Multi-level approval workflow & single-use signed action token gateway (Mod 006).
 */
interface ApprovalWorkflowGatewayInterface
{
    /**
     * Resolves the ordered approval tiers for the requisition's total amount, currency, and cost center.
     *
     * @return list<ApprovalTierSpec>
     */
    public function resolveApprovalChain(PurchaseRequisition $requisition): array;

    /**
     * Starts a Mod 006 workflow instance, opens the first approval step, and generates single-use tokens.
     *
     * @param  list<ApprovalTierSpec>  $tiers
     * @return array{instance: WorkflowInstance, active_step: WorkflowInstanceStep, tokens: list<OneClickTokenBundle>}
     */
    public function startApprovalWorkflow(
        PurchaseRequisition $requisition,
        array $tiers,
        string $submitterUserId,
    ): array;

    /**
     * Validates in-app approver authority and four-eyes rules, records decision, and advances or completes the chain.
     *
     * @return array{step: WorkflowInstanceStep, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string}
     */
    public function validateAndDecideInApp(
        PurchaseRequisition $requisition,
        User $actor,
        string $action,
        ?string $comment,
        ?string $ipAddress,
        ?string $userAgent,
    ): array;

    /**
     * Verifies and atomically consumes a single-use signed one-click token, enforces four-eyes, and advances or completes.
     *
     * @return array{requisition: PurchaseRequisition, actor_user_id: string, channel: ApprovalChannel, step: WorkflowInstanceStep, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string}
     */
    public function validateAndDecideByToken(
        string $rawToken,
        string $action,
        ?string $comment,
        ?string $ipAddress,
        ?string $userAgent,
    ): array;

    /**
     * Returns structured approval chain progress & step statuses for a requisition.
     *
     * @return list<array<string, mixed>>
     */
    public function getApprovalSteps(PurchaseRequisition $requisition): array;

    /**
     * Returns IDs of requisitions currently awaiting action from the given user (direct, role, or active delegation).
     *
     * @return list<string>
     */
    public function getPendingRequisitionIdsForUser(string $tenantId, User $user): array;
}
