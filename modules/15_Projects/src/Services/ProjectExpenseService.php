<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

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
use Modules\Projects\Support\Money;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Out-of-pocket project expenses (travel, permits, rentals...). An expense is recorded as a pending cost entry and
 * counts in the project cost only once approved by a manager, or by the PROJECT_EXPENSE_APPROVAL workflow when the
 * company configured it. On approval the General Ledger books the cost by nature against the reimbursement payable.
 */
final readonly class ProjectExpenseService implements ProjectExpenseServiceInterface
{
    /** Ledger accounts of the by-nature posting of an approved expense. */
    public const string ExpenseAccountCode = '5200';

    public const string ReimbursementPayableAccountCode = '2100';

    /** Expense types that are not plain expenses in the cost categories of the budget. */
    private const array CategoryOfType = ['subcontractor' => 'supplier', 'equipment' => 'equipment'];

    public function __construct(
        private JobCostingAccumulationServiceInterface $costingService,
        private EventDispatcher $dispatcher,
        private ProjectApprovalRouter $approvals,
    ) {}

    /**
     * @param  array{project_id: string, expense_category: string, description: string, amount: string|int|float, incurred_date: string, phase_id?: string|null, task_id?: string|null, receipt_document_id?: string|null, is_billable?: bool|null, userId: string}  $data
     */
    public function recordExpense(array $data): ProjectCostEntry
    {
        return DB::transaction(function () use ($data): ProjectCostEntry {
            $project = Project::query()->whereKey($data['project_id'])->firstOrFail();
            $amount = Money::amount($data['amount']);
            $type = $data['expense_category'];

            $entry = $this->costingService->recordCost(new RecordJobCostDTO(
                projectId: (string) $project->id,
                costCategory: self::CategoryOfType[$type] ?? 'expense',
                costSourceType: 'project_expense',
                costSourceId: $data['receipt_document_id'] ?? (string) Str::uuid7(),
                quantity: '1',
                unitCost: $amount,
                totalCost: $amount,
                incurredDate: $data['incurred_date'],
                phaseId: $data['phase_id'] ?? null,
                taskId: $data['task_id'] ?? null,
                uom: 'PCE',
                isBillable: (bool) ($data['is_billable'] ?? true),
                userId: $data['userId'],
                companyId: (string) ($project->company_id ?? $project->tenant_id),
                description: "[{$type}] {$data['description']}",
                approvalStatus: 'pending',
            ));

            $this->approvals->routeIfConfigured(
                ProjectApprovalRouter::ExpenseWorkflow,
                $entry,
                ['cost_entry_id' => $entry->id, 'project_id' => $project->id, 'amount' => $amount, 'category' => $type, 'description' => $data['description']],
                (string) $project->tenant_id,
                $project->company_id,
                $data['userId']
            );

            return $entry;
        });
    }

    /**
     * Approves a pending expense: it enters the project cost and the ledger.
     *
     * @throws ConflictHttpException when the entry is not a pending expense
     */
    public function approveExpense(ProjectCostEntry $costEntry, string $approverUserId): ProjectCostEntry
    {
        return DB::transaction(function () use ($costEntry, $approverUserId): ProjectCostEntry {
            $this->assertExpense($costEntry);
            $approved = $this->costingService->approveEntry($costEntry, $approverUserId);
            $project = Project::query()->whereKey($approved->project_id)->firstOrFail();
            $companyId = (string) ($project->company_id ?? $project->tenant_id);

            $this->dispatcher->publishAndDispatch(new ProjectExpenseApprovedEvent(
                costEntryId: (string) $approved->id,
                projectId: (string) $project->id,
                expenseCategory: (string) $approved->cost_category,
                totalCost: (float) $approved->total_cost,
                isBillable: (bool) $approved->is_billable,
                incurredDate: $approved->incurred_date->toDateString(),
                tenantId: (string) $project->tenant_id,
                companyId: $companyId
            ));

            $this->dispatcher->publishAndDispatch(new ProjectExpensePostingReadyEvent(
                costEntryId: (string) $approved->id,
                projectId: (string) $project->id,
                expenseCategory: (string) $approved->cost_category,
                amount: Money::amount($approved->total_cost),
                debitAccountCode: self::ExpenseAccountCode,
                creditAccountCode: self::ReimbursementPayableAccountCode,
                incurredDate: $approved->incurred_date->toDateString(),
                tenantId: (string) $project->tenant_id,
                companyId: $companyId
            ));

            return $approved;
        });
    }

    /**
     * Rejects a pending expense; it never counts in the project cost.
     */
    public function rejectExpense(ProjectCostEntry $costEntry, string $approverUserId, string $reason): ProjectCostEntry
    {
        $this->assertExpense($costEntry);

        return $this->costingService->rejectEntry($costEntry, $approverUserId, $reason);
    }

    private function assertExpense(ProjectCostEntry $costEntry): void
    {
        if ($costEntry->cost_source_type !== 'project_expense') {
            throw new ConflictHttpException('This cost entry is not a project expense.');
        }
    }
}
