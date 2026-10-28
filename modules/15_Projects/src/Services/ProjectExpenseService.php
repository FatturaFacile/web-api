<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Events\ProjectExpenseApprovedEvent;
use Modules\Projects\Events\ProjectExpensePostingReadyEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final readonly class ProjectExpenseService implements ProjectExpenseServiceInterface
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService,
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private WorkflowEngineInterface $workflowEngine
    ) {}

    public function recordExpense(array $data): ProjectCostEntry
    {
        return DB::transaction(function () use ($data): ProjectCostEntry {
            /** @var Project $project */
            $project = Project::findOrFail($data['project_id']);

            $amount = (float) $data['amount'];
            $costCategory = $data['expense_category'] ?? 'expense';
            $requiresApproval = $amount > 500.00;

            $costDto = new RecordJobCostDTO(
                projectId: $project->id,
                costCategory: $costCategory,
                costSourceType: 'project_expense',
                costSourceId: $data['receipt_document_id'] ?? (string) Str::uuid7(),
                quantity: 1.0,
                unitCost: $amount,
                totalCost: $amount,
                incurredDate: $data['incurred_date'] ?? now()->toDateString(),
                phaseId: $data['phase_id'] ?? null,
                taskId: $data['task_id'] ?? null,
                uom: 'PCE',
                isBillable: (bool) ($data['is_billable'] ?? true),
                userId: $data['userId'] ?? (string) auth()->id(),
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            );

            $costEntry = $this->costingService->recordCost($costDto);

            if ($requiresApproval) {
                try {
                    $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: 'PROJECT_EXPENSE_APPROVAL',
                        approvable: $costEntry,
                        contextSnapshot: [
                            'cost_entry_id' => $costEntry->id,
                            'project_id' => $project->id,
                            'amount' => $amount,
                            'category' => $costCategory,
                            'description' => $data['description'] ?? '',
                        ],
                        tenantId: (string) $project->tenant_id,
                        companyId: (string) ($project->company_id ?? $project->tenant_id),
                        initiatorUserId: $data['userId'] ?? (string) auth()->id()
                    ));
                } catch (\Throwable $e) {
                    logger()->warning('Workflow engine could not start PROJECT_EXPENSE_APPROVAL: '.$e->getMessage());
                }
            }

            return $costEntry;
        });
    }

    public function approveExpense(ProjectCostEntry $costEntry, string $approverUserId): ProjectCostEntry
    {
        return DB::transaction(function () use ($costEntry, $approverUserId): ProjectCostEntry {
            $costEntry->loadMissing('project');
            $project = $costEntry->project;

            // Audit recording
            $this->auditLedger->record(
                action: 'EXPENSE_APPROVED',
                model: $costEntry,
                payloadBefore: ['status' => 'pending'],
                payloadAfter: ['status' => 'approved', 'approved_by' => $approverUserId],
                moduleCode: 'projects'
            );

            // Dispatch domain event
            $this->dispatcher->publish(new ProjectExpenseApprovedEvent(
                expenseId: (string) $costEntry->id,
                projectId: (string) $project->id,
                expenseCategory: (string) $costEntry->cost_category,
                amount: (float) $costEntry->total_cost,
                approvedBy: $approverUserId,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            // Accounting readiness event
            $this->dispatcher->publish(new ProjectExpensePostingReadyEvent(
                costEntryId: (string) $costEntry->id,
                projectId: (string) $project->id,
                expenseCategory: (string) $costEntry->cost_category,
                amount: (float) $costEntry->total_cost,
                debitAccountCode: '5200',
                creditAccountCode: '2100',
                incurredDate: $costEntry->incurred_date?->toDateString() ?? now()->toDateString(),
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            return $costEntry;
        });
    }
}
