<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Commands\SubmitPurchaseRequisitionCommand;
use App\Application\Procurement\PurchaseRequisition\Support\RequisitionApprovalLogWriter;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ApprovalWorkflowGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\BudgetAvailabilityGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\RequisitionNotificationGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalLogAction;
use App\Domain\Procurement\PurchaseRequisition\Enums\BudgetEnforcementPolicy;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\Services\RequisitionTotalsDomainService;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\BudgetCheckResult;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\OneClickTokenBundle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Handles Purchase Requisition submission: real-time budget enforcement (Mod 019),
 * multi-level delegation chain resolution (Mod 006), single-use tokens, and notifications.
 */
final readonly class SubmitPurchaseRequisitionHandler
{
    public function __construct(
        private RequisitionTotalsDomainService $totalsService,
        private BudgetAvailabilityGatewayInterface $budgetGateway,
        private ApprovalWorkflowGatewayInterface $approvalGateway,
        private RequisitionNotificationGatewayInterface $notifications,
        private RequisitionApprovalLogWriter $logWriter,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    public function handle(SubmitPurchaseRequisitionCommand $command): PurchaseRequisition
    {
        /** @var array{requisition: PurchaseRequisition, blocked_budget: ?BudgetCheckResult, warning_budget: ?BudgetCheckResult, active_step: ?WorkflowInstanceStep, tokens: list<OneClickTokenBundle>} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $requisition = PurchaseRequisition::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($command->requisitionId)
                ->with('lines')
                ->lockForUpdate()
                ->firstOrFail();

            if (! $requisition->statusEnum()->isSubmittable()) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.cannot_submit_status', ['status' => $requisition->status]),
                    'status'
                );
            }

            if ($requisition->lines->isEmpty()) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.validation.lines_required'),
                    'lines'
                );
            }

            $amountsByCostCenter = $this->totalsService->amountsByCostCenter($requisition);
            $fiscalYear = $requisition->requested_delivery_date !== null
                ? (int) $requisition->requested_delivery_date->year
                : (int) CarbonImmutable::now('UTC')->year;

            $budgetResult = $this->budgetGateway->checkAvailability(
                tenantId: $command->tenantId,
                amountsByCostCenter: $amountsByCostCenter,
                currency: (string) $requisition->currency,
                fiscalYear: $fiscalYear,
                excludeRequisitionId: $requisition->id,
            );

            $snapshot = $budgetResult->toSnapshotArray();
            $requisition->update(['budget_check_snapshot' => $snapshot]);

            $this->logWriter->append(
                requisition: $requisition,
                stepLevel: 0,
                actorUserId: $command->actorUserId,
                action: ApprovalLogAction::BudgetChecked,
                channel: $command->channel,
                comment: $budgetResult->isOverBudget
                    ? ($budgetResult->enforcementPolicy === BudgetEnforcementPolicy::HardBlock
                        ? __('purchase_requisition.messages.budget_blocked')
                        : __('purchase_requisition.messages.budget_warning_proceeded'))
                    : null,
                ipAddress: $command->ipAddress,
                userAgent: $command->userAgent,
                metadata: $snapshot,
            );

            if ($budgetResult->isOverBudget && $budgetResult->enforcementPolicy === BudgetEnforcementPolicy::HardBlock) {
                $requisition->update([
                    'status' => RequisitionStatus::BudgetExceededBlocked->value,
                    'is_locked' => false,
                ]);

                $this->auditLedger->record('requisition.budget_blocked', $requisition, null, [
                    'status' => RequisitionStatus::BudgetExceededBlocked->value,
                    'overage_amount' => $budgetResult->overageAmount,
                    'currency' => $budgetResult->currency,
                ]);

                return [
                    'requisition' => $requisition,
                    'blocked_budget' => $budgetResult,
                    'warning_budget' => null,
                    'active_step' => null,
                    'tokens' => [],
                ];
            }

            $warningBudget = null;
            if ($budgetResult->isOverBudget && $budgetResult->enforcementPolicy === BudgetEnforcementPolicy::Warning) {
                $requisition->update(['budget_warning_acknowledged' => true]);
                $warningBudget = $budgetResult;
            } elseif ($command->acknowledgeBudgetWarning) {
                $requisition->update(['budget_warning_acknowledged' => true]);
            }

            $tiers = $this->approvalGateway->resolveApprovalChain($requisition);

            $this->logWriter->append(
                requisition: $requisition,
                stepLevel: 0,
                actorUserId: $command->actorUserId,
                action: ApprovalLogAction::Submitted,
                channel: $command->channel,
                comment: null,
                ipAddress: $command->ipAddress,
                userAgent: $command->userAgent,
            );

            if ($tiers === []) {
                $now = CarbonImmutable::now('UTC');
                $requisition->update([
                    'status' => RequisitionStatus::Approved->value,
                    'submitted_at' => $now,
                    'approved_at' => $now,
                    'current_approval_level' => null,
                    'is_locked' => false,
                ]);

                $this->logWriter->append(
                    requisition: $requisition,
                    stepLevel: 0,
                    actorUserId: $command->actorUserId,
                    action: ApprovalLogAction::Approved,
                    channel: $command->channel,
                    comment: 'Auto-approved (no matching workflow tier required).',
                    ipAddress: $command->ipAddress,
                    userAgent: $command->userAgent,
                );

                return [
                    'requisition' => $requisition,
                    'blocked_budget' => null,
                    'warning_budget' => $warningBudget,
                    'active_step' => null,
                    'tokens' => [],
                ];
            }

            $started = $this->approvalGateway->startApprovalWorkflow(
                requisition: $requisition,
                tiers: $tiers,
                submitterUserId: $command->actorUserId,
            );

            $requisition->update([
                'status' => RequisitionStatus::PendingApproval->value,
                'submitted_at' => CarbonImmutable::now('UTC'),
                'current_approval_level' => (int) $started['active_step']->tier_level,
                'workflow_instance_id' => (string) $started['instance']->id,
                'is_locked' => true,
            ]);

            $this->auditLedger->record('requisition.submitted', $requisition, null, [
                'status' => RequisitionStatus::PendingApproval->value,
                'tiers_count' => count($tiers),
                'current_approval_level' => (int) $started['active_step']->tier_level,
            ]);

            return [
                'requisition' => $requisition,
                'blocked_budget' => null,
                'warning_budget' => $warningBudget,
                'active_step' => $started['active_step'],
                'tokens' => $started['tokens'],
            ];
        });

        $requisition = $outcome['requisition']->fresh([
            'lines',
            'requester',
            'project',
            'approvalLogs.approver',
            'documentLinks',
        ]) ?? $outcome['requisition'];

        if ($outcome['blocked_budget'] !== null) {
            $this->notifications->notifyRequesterBudgetBlocked($requisition, $outcome['blocked_budget']);
        }

        if ($outcome['warning_budget'] !== null) {
            $this->notifications->notifyRequesterBudgetWarning($requisition, $outcome['warning_budget']);
        }

        if ($outcome['active_step'] !== null && $outcome['tokens'] !== []) {
            $this->notifications->notifyApproversStepAssigned(
                $requisition,
                $outcome['active_step'],
                $outcome['tokens'],
            );
        }

        return $requisition;
    }
}
