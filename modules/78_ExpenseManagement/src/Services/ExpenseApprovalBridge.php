<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Modules\ExpenseManagement\Application\Commands\ApproveExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\ApproveExpenseReportHandler;
use Modules\ExpenseManagement\Application\Commands\RejectExpenseReportCommand;
use Modules\ExpenseManagement\Application\Commands\RejectExpenseReportHandler;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Enums\ApprovalOutcome;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Services\WorkflowEngineService;

/**
 * Connects expense reports to the unified approval engine (Mod 006 / Mod 075). When the tenant has an active
 * `EXPENSE_REPORT_APPROVAL` definition, submitting a report starts a workflow instead of waiting for a manual approval,
 * and the outcome of the workflow approves or rejects the report through the same handlers the manual path uses, so the
 * audit log, the events and the posting rules stay identical.
 */
final readonly class ExpenseApprovalBridge
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private WorkflowEngineInterface $engine,
        private ApproveExpenseReportHandler $approve,
        private RejectExpenseReportHandler $reject,
    ) {}

    /**
     * Starts the approval of a submitted report when the tenant configured one; returns the instance or null.
     */
    public function startIfConfigured(ExpenseReport $report, string $userId): ?WorkflowInstance
    {
        $code = (string) config('workflow.engine_subjects.expense_report', 'EXPENSE_REPORT_APPROVAL');

        $configured = WorkflowDefinition::query()
            ->where('tenant_id', $report->tenant_id)
            ->where('code', $code)
            ->where('is_active', true)
            ->exists();

        if (! $configured) {
            return null;
        }

        return $this->engine->start(new StartWorkflowDTO(
            workflowCode: $code,
            approvable: $report,
            contextSnapshot: [],
            tenantId: (string) $report->tenant_id,
            companyId: $report->company_id !== null ? (string) $report->company_id : null,
            initiatorUserId: $userId,
        ));
    }

    /**
     * Applies the end of the approval to the report. A report that is no longer waiting (decided by hand in the meantime) is
     * left alone.
     */
    public function apply(ExpenseReport $report, ApprovalOutcome $outcome): void
    {
        if (! in_array($report->status, [ReportStatus::Submitted, ReportStatus::UnderReview], true)) {
            return;
        }

        [$actor, $comment] = $this->lastDecision($report);

        if ($outcome === ApprovalOutcome::Approved) {
            $this->approve->handle(new ApproveExpenseReportCommand((string) $report->id, $actor, null, $comment ?? 'Approved through the approval workflow.'));

            return;
        }

        $reason = match ($outcome) {
            ApprovalOutcome::Withdrawn => 'Withdrawn by the requester.',
            ApprovalOutcome::Expired, ApprovalOutcome::Cancelled => 'The approval was closed without a decision.',
            default => $comment ?? 'Rejected through the approval workflow.',
        };

        $this->reject->handle(new RejectExpenseReportCommand((string) $report->id, $actor, $reason));
    }

    /**
     * The user and comment of the most recent decision on the report's latest instance; the claimant when there is none
     * (a withdrawal, an expiry), and the engine's own actor never becomes the author of a human decision.
     *
     * @return array{0: string, 1: ?string}
     */
    private function lastDecision(ExpenseReport $report): array
    {
        $instance = WorkflowInstance::query()
            ->where('approvable_type', $report::class)
            ->where('approvable_id', (string) $report->getKey())
            ->latest('started_at')
            ->first();

        $decision = $instance === null ? null : ApprovalDecision::query()
            ->whereIn('workflow_instance_step_id', $instance->steps()->pluck('id'))
            ->where('effective_user_id', '!=', WorkflowEngineService::SystemActor)
            ->latest('decided_at')
            ->first();

        return [(string) ($decision->effective_user_id ?? $report->user_id), $decision?->comment];
    }
}
