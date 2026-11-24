<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\User;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Workflow\Contracts\ApprovalMatrixEvaluatorInterface;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

/**
 * Approval of purchase orders and requisitions through the workflow engine. The workflow starts when the document is
 * submitted (not when it is created as a draft). Approval matrices by amount (gross order total, estimated requisition
 * total) and cost centre decide the levels; without a matching matrix the tenant's own `PURCHASE_ORDER_APPROVAL` /
 * `PURCHASE_REQUISITION_APPROVAL` definition runs when configured, otherwise the document is approved at once.
 */
final readonly class ProcurementApprovalService
{
    public const string OrderMatrixWorkflow = 'PROCUREMENT_PO_MATRIX_APPROVAL';

    public const string RequisitionMatrixWorkflow = 'PROCUREMENT_PR_MATRIX_APPROVAL';

    /**
     * Matrix target name accepted by the API => document class.
     *
     * @var array<string, class-string<Model>>
     */
    public const array Targets = [
        'purchase_order' => PurchaseOrder::class,
        'purchase_requisition' => PurchaseRequisition::class,
    ];

    public function __construct(
        private WorkflowEngineInterface $engine,
        private ApprovalMatrixEvaluatorInterface $matrices,
        private EventDispatcher $dispatcher,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    /**
     * Submits a draft (or rejected) purchase order or requisition for approval and starts its workflow.
     *
     * @throws ProcurementRuleViolation when the status does not allow a submission
     */
    public function submit(PurchaseOrder|PurchaseRequisition $document, ?User $submitter, ?string $costCenterId = null): PurchaseOrder|PurchaseRequisition
    {
        return DB::transaction(function () use ($document, $submitter, $costCenterId): PurchaseOrder|PurchaseRequisition {
            /** @var PurchaseOrder|PurchaseRequisition $locked */
            $locked = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $isOrder = $locked instanceof PurchaseOrder;
            $from = (string) $locked->status;

            $allowed = $isOrder
                ? in_array($from, [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Rejected->value], true)
                : in_array($from, [PurchaseRequisitionStatus::Draft->value, PurchaseRequisitionStatus::Rejected->value], true);

            if (! $allowed) {
                throw new ProcurementRuleViolation("A document in status {$from} cannot be submitted for approval.", 'status');
            }

            if ($isOrder && $locked->lines()->count() === 0 && (float) $locked->total_amount <= 0.0) {
                throw new ProcurementRuleViolation('A purchase order without lines cannot be submitted.', 'lines');
            }

            if ($costCenterId !== null) {
                $locked->cost_center_id = $costCenterId;
            }

            $tenantId = (string) $locked->tenant_id;
            $amount = $isOrder ? (string) $locked->total_amount : (string) $locked->total_estimated_amount;
            $context = [
                'amount' => $amount,
                'cost_center_id' => $locked->cost_center_id,
                'four_eyes' => true,
                'maker_user_ids' => array_values(array_filter([$submitter !== null ? (string) $submitter->id : '', (string) ($locked->created_by ?? '')])),
            ];

            $workflowCode = $this->workflowCodeFor($locked, $tenantId, $context);
            $locked->fill(['submitted_at' => now()]);

            if ($workflowCode === null) {
                $locked->save();
                $this->markApproved($locked, $submitter?->id !== null ? (string) $submitter->id : null);

                return $locked->fresh() ?? $locked;
            }

            $locked->fill(['status' => $isOrder ? PurchaseOrderStatus::PendingApproval->value : PurchaseRequisitionStatus::PendingApproval->value, 'is_locked' => true])->save();

            $instance = $this->engine->start(new StartWorkflowDTO(
                workflowCode: $workflowCode,
                approvable: $locked,
                contextSnapshot: $context,
                tenantId: $tenantId,
                companyId: $locked->company_id !== null ? (string) $locked->company_id : null,
                initiatorUserId: $submitter?->id !== null ? (string) $submitter->id : null,
            ));

            $locked->fill(['workflow_instance_id' => $instance->id])->save();

            $this->auditLedger->record(
                action: $isOrder ? 'PURCHASE_ORDER_SUBMITTED_FOR_APPROVAL' : 'REQUISITION_SUBMITTED_FOR_APPROVAL',
                model: $locked,
                payloadBefore: ['status' => $from],
                payloadAfter: ['status' => $locked->status, 'workflow_instance_id' => $instance->id, 'workflow_code' => $workflowCode]
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Whether submitting the document would start an approval workflow (a matching matrix level or a configured
     * definition).
     */
    public function requiresApproval(PurchaseOrder|PurchaseRequisition $document): bool
    {
        $amount = $document instanceof PurchaseOrder ? (string) ($document->total_amount ?? $document->total_gross) : (string) $document->total_estimated_amount;

        return $this->workflowCodeFor($document, (string) $document->tenant_id, ['amount' => $amount, 'cost_center_id' => $document->cost_center_id]) !== null;
    }

    /**
     * Applies the outcome of the workflow to the document: approved, rejected, or back to draft when cancelled.
     */
    public function applyOutcome(PurchaseOrder|PurchaseRequisition $document, ?WorkflowInstance $instance, string $outcome): void
    {
        $pending = $document instanceof PurchaseOrder ? PurchaseOrderStatus::PendingApproval->value : PurchaseRequisitionStatus::PendingApproval->value;

        if ((string) $document->status !== $pending) {
            return;
        }

        match ($outcome) {
            'approved' => $this->markApproved($document, $instance?->assigned_to_user_id !== null ? (string) $instance->assigned_to_user_id : null),
            'rejected' => $document->update(['status' => 'rejected', 'is_locked' => false]),
            default => $document->update(['status' => 'draft', 'is_locked' => false, 'workflow_instance_id' => null]),
        };
    }

    /**
     * Manual decision on a document waiting for approval without a running workflow (four-eyes: the creator cannot
     * approve).
     *
     * @throws ProcurementRuleViolation when a workflow is running or the user created the document
     */
    public function decideManually(PurchaseOrder|PurchaseRequisition $document, User $user, bool $approve): PurchaseOrder|PurchaseRequisition
    {
        return DB::transaction(function () use ($document, $user, $approve): PurchaseOrder|PurchaseRequisition {
            /** @var PurchaseOrder|PurchaseRequisition $locked */
            $locked = $document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $locked->status !== 'pending_approval') {
                throw new ProcurementRuleViolation("A document in status {$locked->status} is not waiting for approval.", 'status');
            }

            $running = WorkflowInstance::query()
                ->where('approvable_type', $locked::class)
                ->where('approvable_id', (string) $locked->getKey())
                ->whereIn('status', ['pending', 'in_progress'])
                ->exists();

            if ($running) {
                throw new ProcurementRuleViolation('The approval workflow of this document is running: decide its steps in the workflow inbox.', 'status');
            }

            if ($approve && (string) $locked->created_by === (string) $user->id) {
                throw new ProcurementRuleViolation('Four-eyes rule: the creator of a document cannot approve it.', 'status');
            }

            $approve
                ? $this->markApproved($locked, (string) $user->id)
                : $locked->update(['status' => 'rejected', 'is_locked' => false]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Creates an approval matrix (levels by amount, optionally restricted to a cost centre) for orders or requisitions.
     *
     * @param  array{target: string, name: string, cost_center_id?: ?string, tiers: list<array{tier_level: int, approver_type: string, approver_value: string, threshold_min?: numeric-string|float|int|null, threshold_max?: numeric-string|float|int|null}>}  $data
     */
    public function createMatrix(string $tenantId, array $data): ApprovalMatrix
    {
        return DB::transaction(function () use ($tenantId, $data): ApprovalMatrix {
            $matrix = ApprovalMatrix::create([
                'tenant_id' => $tenantId,
                'code' => strtoupper(substr($data['target'], 0, 8)).'-'.Str::uuid7(),
                'name' => $data['name'],
                'target_entity_type' => self::Targets[$data['target']],
                'is_active' => true,
            ]);

            if (! empty($data['cost_center_id'])) {
                ApprovalMatrixRule::create([
                    'tenant_id' => $tenantId,
                    'approval_matrix_id' => $matrix->id,
                    'field_name' => 'cost_center_id',
                    'operator' => '=',
                    'value_string' => $data['cost_center_id'],
                    'cost_center_id' => $data['cost_center_id'],
                ]);
            }

            foreach ($data['tiers'] as $tier) {
                ApprovalMatrixTier::create([
                    'tenant_id' => $tenantId,
                    'approval_matrix_id' => $matrix->id,
                    'tier_level' => $tier['tier_level'],
                    'approver_type' => $tier['approver_type'],
                    'approver_value' => $tier['approver_value'],
                    'approval_type' => 'and_parallel',
                    'threshold_min' => $tier['threshold_min'] ?? null,
                    'threshold_max' => $tier['threshold_max'] ?? null,
                ]);
            }

            return $matrix->load(['rules', 'tiers']);
        });
    }

    /**
     * The workflow to run: the matrix workflow when a matrix gives levels for the amount, else the tenant's own
     * definition, else none (approved at once). A matrix that exists but gives no level means no approval is needed.
     *
     * @param  array<string, mixed>  $context
     */
    private function workflowCodeFor(PurchaseOrder|PurchaseRequisition $document, string $tenantId, array $context): ?string
    {
        $tiers = $this->matrices->tiersFor($tenantId, $document::class, $document, $context);

        if ($tiers !== null) {
            if ($tiers === []) {
                return null;
            }

            $code = $document instanceof PurchaseOrder ? self::OrderMatrixWorkflow : self::RequisitionMatrixWorkflow;
            $this->ensureMatrixDefinition($tenantId, $code, $document::class);

            return $code;
        }

        $configured = $document instanceof PurchaseOrder
            ? (bccomp((string) $document->total_amount, '50000', 2) > 0 ? ['HIGH_VALUE_PURCHASE_APPROVAL', 'PURCHASE_ORDER_APPROVAL'] : ['PURCHASE_ORDER_APPROVAL'])
            : ['PURCHASE_REQUISITION_APPROVAL'];

        foreach ($configured as $code) {
            if (WorkflowDefinition::query()->where('tenant_id', $tenantId)->where('code', $code)->where('is_active', true)->exists()) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Sets the document approved and announces it.
     */
    private function markApproved(PurchaseOrder|PurchaseRequisition $document, ?string $approverId): void
    {
        if ($document instanceof PurchaseOrder) {
            $document->update(['status' => PurchaseOrderStatus::Approved->value, 'is_locked' => false, 'approved_at' => now(), 'approved_by' => $approverId]);

            $this->dispatcher->publishAndDispatch(new PurchaseOrderApprovedEvent(
                purchaseOrderId: (string) $document->id,
                poNumber: (string) ($document->po_number ?? $document->order_number),
                approvedByUserId: $approverId,
                totalAmount: (string) $document->total_amount,
                currency: (string) $document->currency,
                tenantId: (string) $document->tenant_id,
                companyId: (string) ($document->company_id ?? ''),
                supplierId: (string) $document->supplier_id,
                projectId: $document->project_id !== null ? (string) $document->project_id : null,
            ));

            return;
        }

        $document->update(['status' => PurchaseRequisitionStatus::Approved->value, 'is_locked' => false, 'approved_at' => now()]);

        $this->dispatcher->publishAndDispatch(new PurchaseRequisitionApprovedEvent(
            requisitionId: (string) $document->id,
            requisitionNumber: (string) $document->requisition_number,
            approvedByUserId: $approverId ?? 'system',
            tenantId: (string) $document->tenant_id,
            companyId: (string) ($document->company_id ?? ''),
        ));
    }

    /**
     * One definition per tenant and document type: start, one sequential node fed by the matrices, end.
     *
     * @param  class-string<Model>  $target
     */
    private function ensureMatrixDefinition(string $tenantId, string $code, string $target): void
    {
        if (WorkflowDefinition::query()->where('tenant_id', $tenantId)->where('code', $code)->where('is_active', true)->exists()) {
            return;
        }

        $definition = WorkflowDefinition::create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $target === PurchaseOrder::class ? 'Purchase order approval by matrix' : 'Purchase requisition approval by matrix',
            'target_entity_type' => $target,
            'version' => 1,
            'status' => 'published',
            'is_active' => true,
            'published_at' => now(),
        ]);

        $node = static fn (string $key, string $type, array $extra = []): WorkflowNode => WorkflowNode::create([
            'tenant_id' => $tenantId,
            'workflow_definition_id' => $definition->id,
            'node_key' => $key,
            'name' => ucfirst($key),
            'node_type' => $type,
            ...$extra,
        ]);

        $start = $node('start', 'start');
        $approval = $node('approval', 'approval', ['approval_type' => 'and_parallel', 'assignee_type' => 'matrix_sequential', 'timeout_hours' => 720]);
        $end = $node('end', 'end');

        foreach ([[$start, $approval, 'start'], [$approval, $end, 'approved']] as [$from, $to, $trigger]) {
            WorkflowTransition::create([
                'tenant_id' => $tenantId,
                'workflow_definition_id' => $definition->id,
                'from_node_id' => $from->id,
                'to_node_id' => $to->id,
                'action_trigger' => $trigger,
            ]);
        }
    }
}
