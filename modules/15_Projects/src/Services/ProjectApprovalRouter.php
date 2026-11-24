<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowDefinition;

/**
 * Sends a project document to the approval workflow when the company has configured one for it, and otherwise leaves
 * the decision to a manager through the approve and reject endpoints. No amount threshold is hard-coded: the company
 * expresses its approval rules in the workflow definitions (codes in the constants below).
 */
final readonly class ProjectApprovalRouter
{
    public const string BudgetWorkflow = 'PROJECT_BUDGET_APPROVAL';

    public const string ChangeOrderWorkflow = 'PROJECT_CHANGE_REQUEST_APPROVAL';

    public const string ExpenseWorkflow = 'PROJECT_EXPENSE_APPROVAL';

    public function __construct(private WorkflowEngineInterface $workflowEngine) {}

    /**
     * Whether the company has an active workflow definition with this code.
     */
    public function isConfigured(string $workflowCode, string $tenantId): bool
    {
        return WorkflowDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $workflowCode)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Starts the workflow when configured. Answers whether the document now waits for the workflow decision.
     *
     * @param  array<string, mixed>  $context
     */
    public function routeIfConfigured(string $workflowCode, Model $approvable, array $context, string $tenantId, ?string $companyId, string $initiatorUserId): bool
    {
        if (! $this->isConfigured($workflowCode, $tenantId)) {
            return false;
        }

        $this->workflowEngine->start(new StartWorkflowDTO(
            workflowCode: $workflowCode,
            approvable: $approvable,
            contextSnapshot: $context,
            tenantId: $tenantId,
            companyId: $companyId ?? $tenantId,
            initiatorUserId: $initiatorUserId
        ));

        return true;
    }
}
