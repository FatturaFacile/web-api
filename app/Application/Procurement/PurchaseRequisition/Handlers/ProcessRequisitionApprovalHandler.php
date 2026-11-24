<?php

declare(strict_types=1);

namespace App\Application\Procurement\PurchaseRequisition\Handlers;

use App\Application\Procurement\PurchaseRequisition\Commands\ProcessRequisitionApprovalCommand;
use App\Application\Procurement\PurchaseRequisition\Support\RequisitionApprovalLogWriter;
use App\Domain\Procurement\PurchaseRequisition\Contracts\ApprovalWorkflowGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Contracts\RequisitionNotificationGatewayInterface;
use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalChannel;
use App\Domain\Procurement\PurchaseRequisition\Enums\ApprovalLogAction;
use App\Domain\Procurement\PurchaseRequisition\Enums\RequisitionStatus;
use App\Domain\Procurement\PurchaseRequisition\Models\PurchaseRequisition;
use App\Domain\Procurement\PurchaseRequisition\ValueObjects\OneClickTokenBundle;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Services\ProcurementAuditLedgerService;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Handles in-app and signed single-use 1-click approve/reject decisions,
 * appending immutable approval audit logs and advancing multi-level chains.
 */
final readonly class ProcessRequisitionApprovalHandler
{
    public function __construct(
        private ApprovalWorkflowGatewayInterface $approvalGateway,
        private RequisitionNotificationGatewayInterface $notifications,
        private RequisitionApprovalLogWriter $logWriter,
        private ProcurementAuditLedgerService $auditLedger,
        private TenantContext $tenantContext,
    ) {}

    public function handle(ProcessRequisitionApprovalCommand $command): PurchaseRequisition
    {
        if ($command->oneClickToken !== null && trim($command->oneClickToken) !== '') {
            return $this->handleOneClickToken($command);
        }

        return $this->handleInApp($command);
    }

    private function handleInApp(ProcessRequisitionApprovalCommand $command): PurchaseRequisition
    {
        $tenantId = (string) $command->tenantId;
        $requisitionId = (string) $command->requisitionId;
        $actorUserId = (string) $command->actorUserId;

        /** @var User $actor */
        $actor = User::query()->findOrFail($actorUserId);

        /** @var array{requisition: PurchaseRequisition, actor: User, step_level: int, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string, comment: ?string} $result */
        $result = DB::transaction(function () use ($command, $tenantId, $requisitionId, $actor): array {
            $requisition = PurchaseRequisition::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($requisitionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($requisition->statusEnum() !== RequisitionStatus::PendingApproval) {
                throw new ProcurementRuleViolation(
                    __('purchase_requisition.errors.not_pending_approval', ['status' => $requisition->status]),
                    'status'
                );
            }

            $decision = $this->approvalGateway->validateAndDecideInApp(
                requisition: $requisition,
                actor: $actor,
                action: $command->action,
                comment: $command->comment,
                ipAddress: $command->ipAddress,
                userAgent: $command->userAgent,
            );

            return $this->finalizeDecisionState(
                requisition: $requisition,
                actor: $actor,
                channel: $command->channel,
                comment: $command->comment,
                ipAddress: $command->ipAddress,
                userAgent: $command->userAgent,
                decision: $decision,
            );
        });

        return $this->dispatchOutcomeNotifications($result);
    }

    private function handleOneClickToken(ProcessRequisitionApprovalCommand $command): PurchaseRequisition
    {
        $decision = $this->approvalGateway->validateAndDecideByToken(
            rawToken: (string) $command->oneClickToken,
            action: $command->action,
            comment: $command->comment,
            ipAddress: $command->ipAddress,
            userAgent: $command->userAgent,
        );

        /** @var PurchaseRequisition $requisition */
        $requisition = $decision['requisition'];
        $tenant = Tenant::query()->findOrFail($requisition->tenant_id);

        /** @var array{requisition: PurchaseRequisition, actor: User, step_level: int, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string, comment: ?string} $result */
        $result = $this->tenantContext->runWithTenant($tenant, function () use ($decision, $command, $requisition, $tenant): array {
            return DB::transaction(function () use ($decision, $command, $requisition, $tenant): array {
                $lockedRequisition = PurchaseRequisition::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($requisition->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var User $actor */
                $actor = User::query()->findOrFail($decision['actor_user_id']);

                $channel = $decision['channel'];

                return $this->finalizeDecisionState(
                    requisition: $lockedRequisition,
                    actor: $actor,
                    channel: $channel,
                    comment: $command->comment,
                    ipAddress: $command->ipAddress,
                    userAgent: $command->userAgent,
                    decision: $decision,
                );
            });
        });

        return $this->tenantContext->runWithTenant(
            $tenant,
            fn (): PurchaseRequisition => $this->dispatchOutcomeNotifications($result)
        );
    }

    /**
     * @param  array{step: WorkflowInstanceStep, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string}  $decision
     * @return array{requisition: PurchaseRequisition, actor: User, step_level: int, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string, comment: ?string}
     */
    private function finalizeDecisionState(
        PurchaseRequisition $requisition,
        User $actor,
        ApprovalChannel $channel,
        ?string $comment,
        ?string $ipAddress,
        ?string $userAgent,
        array $decision,
    ): array {
        /** @var WorkflowInstanceStep $step */
        $step = $decision['step'];
        $stepLevel = (int) $step->tier_level;
        $isApprovedOutcome = $decision['outcome'] === 'approved';
        $logAction = $decision['outcome'] === 'rejected'
            ? ApprovalLogAction::Rejected
            : ApprovalLogAction::Approved;

        $this->logWriter->append(
            requisition: $requisition,
            stepLevel: $stepLevel,
            actorUserId: (string) $actor->id,
            action: $logAction,
            channel: $channel,
            comment: $comment,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            metadata: [
                'workflow_step_id' => (string) $step->id,
                'completed' => $decision['completed'],
            ],
        );

        if (! $decision['completed'] && $decision['next_step'] !== null) {
            $requisition->update([
                'status' => RequisitionStatus::PendingApproval->value,
                'current_approval_level' => (int) $decision['next_step']->tier_level,
            ]);
        } elseif ($isApprovedOutcome) {
            $requisition->update([
                'status' => RequisitionStatus::Approved->value,
                'approved_at' => CarbonImmutable::now('UTC'),
                'current_approval_level' => null,
                'is_locked' => false,
            ]);
        } else {
            $requisition->update([
                'status' => RequisitionStatus::Rejected->value,
                'rejected_at' => CarbonImmutable::now('UTC'),
                'rejection_reason' => $comment,
                'current_approval_level' => null,
                'is_locked' => false,
            ]);
        }

        $this->auditLedger->record('requisition.approval_decision', $requisition, null, [
            'step_level' => $stepLevel,
            'decision' => $logAction->value,
            'channel' => $channel->value,
            'completed' => $decision['completed'],
            'actor_user_id' => (string) $actor->id,
        ]);

        return [
            'requisition' => $requisition,
            'actor' => $actor,
            'step_level' => $stepLevel,
            'next_step' => $decision['next_step'],
            'next_tokens' => $decision['next_tokens'],
            'completed' => $decision['completed'],
            'outcome' => $decision['outcome'],
            'comment' => $comment,
        ];
    }

    /**
     * @param  array{requisition: PurchaseRequisition, actor: User, step_level: int, next_step: ?WorkflowInstanceStep, next_tokens: list<OneClickTokenBundle>, completed: bool, outcome: string, comment: ?string}  $result
     */
    private function dispatchOutcomeNotifications(array $result): PurchaseRequisition
    {
        $requisition = $result['requisition']->fresh([
            'lines',
            'requester',
            'project',
            'approvalLogs.approver',
            'documentLinks',
        ]) ?? $result['requisition'];

        if (! $result['completed'] && $result['next_step'] !== null && $result['next_tokens'] !== []) {
            $this->notifications->notifyApproversStepAssigned(
                $requisition,
                $result['next_step'],
                $result['next_tokens'],
            );

            return $requisition;
        }

        if ($result['completed'] && $result['outcome'] === 'approved') {
            $this->notifications->notifyRequesterApproved(
                $requisition,
                $result['actor'],
                $result['comment'],
            );

            return $requisition;
        }

        if ($result['completed'] && $result['outcome'] === 'rejected') {
            $this->notifications->notifyRequesterRejected(
                $requisition,
                $result['actor'],
                $result['step_level'],
                $result['comment'] ?? '',
            );
        }

        return $requisition;
    }
}
