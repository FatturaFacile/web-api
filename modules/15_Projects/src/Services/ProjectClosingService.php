<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Events\ProjectClosedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Closing of a project (chiusura commessa) with its checks and final ledger settlement.
 *
 * A project closes only when nothing is left pending: no timesheet waiting for submission or approval, no pending
 * expense, no approved billable hours or costs left unbilled on a time and materials contract, no completed milestone left unbilled, no draft invoice
 * left unissued and, for projects that recognise revenue, no recognised revenue left uninvoiced. Open purchase order
 * commitments are reported as a warning. When the company configured PROJECT_CLOSURE_APPROVAL the project waits for
 * the workflow (status pending_closure); otherwise it closes at once.
 *
 * At closure the revenue of a project with a recognition method is brought to the invoiced revenue (residual contract
 * asset settled against revenue) and the cost still capitalised in work in progress is released to the cost of
 * revenue (ProjectClosureReadyEvent, posted by the General Ledger).
 */
final readonly class ProjectClosingService
{
    public const string ClosureWorkflow = 'PROJECT_CLOSURE_APPROVAL';

    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
        private ProjectApprovalRouter $approvals,
        private ProjectBillingService $billing,
        private ProjectCommitmentService $commitments,
        private ProjectInvoiceReader $invoices,
        private JobCostingAccumulationServiceInterface $costing,
    ) {}

    /**
     * What prevents the closure (blockers) and what deserves attention (warnings).
     *
     * @return array{can_close: bool, blockers: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}
     */
    public function checks(Project $project): array
    {
        $blockers = [];
        $warnings = [];

        $openTimesheets = ProjectTimesheet::query()->where('project_id', $project->id)->whereIn('status', ProjectTimesheet::OpenStatuses)->count();
        if ($openTimesheets > 0) {
            $blockers[] = ['code' => 'open_timesheets', 'message' => "{$openTimesheets} timesheets are still draft, submitted or rejected."];
        }

        $pendingCosts = ProjectCostEntry::query()->where('project_id', $project->id)->where('approval_status', 'pending')->count();
        if ($pendingCosts > 0) {
            $blockers[] = ['code' => 'pending_expenses', 'message' => "{$pendingCosts} expenses are waiting for approval."];
        }

        $unbilled = $this->billing->billsTimeAndMaterials($project) ? $this->billing->unbilledValue($project) : '0';
        if (Money::isPositive($unbilled)) {
            $blockers[] = ['code' => 'unbilled_time_and_materials', 'message' => "Approved billable hours and costs worth {$unbilled} are not invoiced."];
        }

        $unbilledMilestones = $this->billing->unbilledMilestoneCount($project);
        if ($unbilledMilestones > 0) {
            $blockers[] = ['code' => 'unbilled_milestones', 'message' => "{$unbilledMilestones} completed milestones are not invoiced."];
        }

        $drafts = SalesInvoice::query()->where('project_id', $project->id)->where('status', 'draft')->count();
        if ($drafts > 0) {
            $blockers[] = ['code' => 'draft_invoices', 'message' => "{$drafts} draft invoices of the project are not issued yet."];
        }

        if ($project->revenue_recognition_method !== null) {
            $unbilledRevenue = Money::sub($this->lastRecognisedRevenue($project), $this->invoices->billedRevenue($project));

            if (Money::isPositive($unbilledRevenue)) {
                $blockers[] = ['code' => 'unbilled_recognised_revenue', 'message' => 'Recognised revenue exceeds the invoiced revenue by '.Money::amount($unbilledRevenue).': invoice it before closing.'];
            }
        }

        $committed = $this->commitments->openCommitments($project);
        if (Money::isPositive($committed)) {
            $warnings[] = ['code' => 'open_commitments', 'message' => "Purchase orders worth {$committed} are still open on the project."];
        }

        return ['can_close' => $blockers === [], 'blockers' => $blockers, 'warnings' => $warnings];
    }

    /**
     * Closes the project, or sends it to the closure workflow when configured.
     *
     * @throws ValidationException listing the blockers
     * @throws ConflictHttpException when the project is already closed, cancelled or waiting for closure approval
     */
    public function close(Project $project, string $actorUserId, ?string $reason = null): Project
    {
        return DB::transaction(function () use ($project, $actorUserId, $reason): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen() || $locked->status === 'pending_closure') {
                throw new ConflictHttpException("Project {$locked->project_number} is {$locked->status}: it cannot be closed again.");
            }

            $checks = $this->checks($locked);

            if (! $checks['can_close']) {
                throw ValidationException::withMessages(['closure' => array_map(static fn (array $blocker): string => $blocker['message'], $checks['blockers'])]);
            }

            $routed = $this->approvals->routeIfConfigured(
                self::ClosureWorkflow,
                $locked,
                ['project_id' => $locked->id, 'project_number' => $locked->project_number, 'contract_value' => $locked->contract_value, 'closure_reason' => $reason],
                (string) $locked->tenant_id,
                $locked->company_id,
                $actorUserId
            );

            if ($routed) {
                $locked->update(['status' => 'pending_closure']);
                $this->audit->record($locked, $actorUserId, 'closure_requested', 'Closure sent to the approval workflow'.($reason ? ": {$reason}" : ''), [], ['status' => 'pending_closure']);

                return $locked;
            }

            return $this->finalize($locked, $actorUserId, $reason ?? 'Project closed');
        });
    }

    /**
     * Closes the project and books the final settlement. Called directly or when the closure workflow approves.
     */
    public function finalize(Project $project, string $actorUserId, string $reason): Project
    {
        return DB::transaction(function () use ($project, $actorUserId, $reason): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, Project::FinalStatuses, true)) {
                return $locked;
            }

            $summary = $this->costing->rollupProjectCosts($locked);
            $actualCost = Money::of($summary->total_actual_cost);
            $billed = $this->invoices->billedRevenue($locked);
            $before = ['status' => $locked->status];

            $wipRelease = null;
            $settlement = null;

            if ($locked->revenue_recognition_method !== null) {
                $last = ProjectRevenueRecognition::query()->where('project_id', $locked->id)->orderByDesc('accounting_period_date')->first();
                $wipRelease = Money::amount(Money::sub($actualCost, $last->cumulative_cost_of_revenue ?? 0));
                // The contract asset in the ledger is the sum of the run adjustments; reversing it leaves exactly the
                // invoiced revenue in the result, including invoices issued after the last run.
                $settlement = Money::amount(Money::neg($last->contract_asset_balance ?? 0));
                $summary->update(['total_recognized_revenue' => $billed]);
            }

            $locked->update([
                'status' => 'closed',
                'actual_end_date' => $locked->actual_end_date ?? now()->toDateString(),
                'closed_at' => now(),
                'closed_by' => $actorUserId,
            ]);

            $companyId = (string) ($locked->company_id ?? $locked->tenant_id);

            $this->dispatcher->publishAndDispatch(new ProjectClosedEvent(
                projectId: (string) $locked->id,
                projectNumber: (string) $locked->project_number,
                closedBy: $actorUserId,
                closureReason: $reason,
                tenantId: (string) $locked->tenant_id,
                companyId: $companyId
            ));

            $this->dispatcher->publishAndDispatch(new ProjectClosureReadyEvent(
                projectId: (string) $locked->id,
                finalContractValue: Money::amount($locked->contract_value),
                finalCost: Money::amount($actualCost),
                unbilledWipBalance: Money::amount(Money::max(Money::sub($actualCost, $billed), 0)),
                retainedEarningsImpact: Money::amount(Money::sub($billed, $actualCost)),
                fiscalYear: now()->format('Y'),
                tenantId: (string) $locked->tenant_id,
                companyId: $companyId,
                wipReleaseAmount: $wipRelease,
                contractAssetSettlement: $settlement,
            ));

            $this->audit->record($locked, $actorUserId, 'project_closed', "Closed project {$locked->project_number}: final cost {$summary->total_actual_cost}, invoiced {$billed}", $before, ['status' => 'closed', 'wip_release' => $wipRelease, 'contract_asset_settlement' => $settlement]);

            return $locked;
        });
    }

    /**
     * The workflow refused the closure: the project goes back to active.
     */
    public function reopenAfterRejectedClosure(Project $project, string $actorUserId): Project
    {
        return DB::transaction(function () use ($project, $actorUserId): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'pending_closure') {
                $locked->update(['status' => 'active']);
                $this->audit->record($locked, $actorUserId, 'closure_rejected', 'Closure refused by the approval workflow', ['status' => 'pending_closure'], ['status' => 'active']);
            }

            return $locked;
        });
    }

    private function lastRecognisedRevenue(Project $project): string
    {
        $last = ProjectRevenueRecognition::query()->where('project_id', $project->id)->orderByDesc('accounting_period_date')->first();

        return Money::of($last->cumulative_recognized_revenue ?? 0);
    }
}
