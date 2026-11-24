<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Multi-step approval of received supplier invoices, run by the workflow engine. Thresholds and cost centres live in
 * approval matrices. A company with no matrix keeps paying as before. Accounting registration is not touched: only
 * the payment waits for the approval.
 */
final readonly class PassiveInvoiceApprovalService
{
    public const string WorkflowCode = 'PASSIVE_INVOICE_APPROVAL';

    /**
     * Hours before a step counts as overdue. An overdue step keeps blocking the payment until it is resubmitted.
     */
    private const int StepTimeoutHours = 720;

    public function __construct(
        private WorkflowEngineInterface $engine,
        private ApprovalMatrixEvaluatorInterface $matrices,
    ) {}

    /**
     * Sets the starting state of a new invoice: credit notes are never paid, so they stay out of the process.
     */
    public function flagNewInvoice(PassiveInvoice $invoice): void
    {
        if ($invoice->document_type === 'TD04' || empty($invoice->tenant_id)) {
            return;
        }

        $hasMatrix = ApprovalMatrix::withoutGlobalScopes()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('target_entity_type', PassiveInvoice::class)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        $invoice->approval_status = $hasMatrix ? 'pending' : 'not_required';
    }

    /**
     * Starts the approval. The submitter becomes the maker of the four-eyes rule. An amount below every threshold needs
     * no approval and clears at once.
     *
     * @throws ConflictHttpException when the invoice is not waiting for a submission
     */
    public function submit(PassiveInvoice $invoice, ?string $costCenterId, User $submitter): PassiveInvoice
    {
        return DB::transaction(function () use ($invoice, $costCenterId, $submitter): PassiveInvoice {
            $locked = PassiveInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if (! in_array($locked->approval_status, ['pending', 'rejected', 'in_approval'], true)) {
                throw new ConflictHttpException("This invoice is {$locked->approval_status}: nothing to submit.");
            }

            $this->closeStalledInstance($locked);

            $locked->fill(['cost_center_id' => $costCenterId ?? $locked->cost_center_id]);
            $tenantId = (string) $locked->tenant_id;
            $context = [
                'amount' => (string) $locked->total_amount,
                'cost_center_id' => $locked->cost_center_id,
                'four_eyes' => true,
                'maker_user_ids' => [(string) $submitter->id],
            ];

            $tiers = $this->matrices->tiersFor($tenantId, PassiveInvoice::class, $locked, $context);

            if ($tiers === null || $tiers === []) {
                $locked->fill(['approval_status' => 'not_required', 'approval_submitted_by' => $submitter->id, 'approval_decided_at' => now()])->save();

                return $locked;
            }

            $this->ensureDefinition($tenantId);

            $instance = $this->engine->start(new StartWorkflowDTO(
                workflowCode: self::WorkflowCode,
                approvable: $locked,
                contextSnapshot: $context,
                tenantId: $tenantId,
                initiatorUserId: (string) $submitter->id,
            ));

            $locked->fill([
                'approval_status' => 'in_approval',
                'approval_workflow_instance_id' => $instance->id,
                'approval_submitted_by' => $submitter->id,
                'approval_decided_at' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array{name: string, cost_center_id?: ?string, tiers: list<array{tier_level: int, approver_type: string, approver_value: string, threshold_min?: numeric-string|float|int|null, threshold_max?: numeric-string|float|int|null}>}  $data
     */
    public function createMatrix(string $tenantId, array $data): ApprovalMatrix
    {
        return DB::transaction(function () use ($tenantId, $data): ApprovalMatrix {
            $matrix = ApprovalMatrix::create([
                'tenant_id' => $tenantId,
                'code' => 'PI-'.Str::uuid7(),
                'name' => $data['name'],
                'target_entity_type' => PassiveInvoice::class,
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
     * An instance whose steps all ran out would block a new submission forever.
     *
     * @throws ConflictHttpException when the approval is still running
     */
    private function closeStalledInstance(PassiveInvoice $invoice): void
    {
        $active = WorkflowInstance::query()
            ->where('approvable_type', PassiveInvoice::class)
            ->where('approvable_id', (string) $invoice->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($active === null) {
            return;
        }

        if ($active->activeSteps()->exists()) {
            throw new ConflictHttpException('The approval of this invoice is already running.');
        }

        $this->engine->cancel($active, 'Every step ran out of time: resubmitted.');
    }

    /**
     * The engine needs one definition per tenant: start, one sequential node fed by the matrices, end.
     */
    private function ensureDefinition(string $tenantId): void
    {
        $exists = WorkflowDefinition::query()->where('tenant_id', $tenantId)->where('code', self::WorkflowCode)->where('is_active', true)->exists();

        if ($exists) {
            return;
        }

        $definition = WorkflowDefinition::create([
            'tenant_id' => $tenantId,
            'code' => self::WorkflowCode,
            'name' => 'Supplier invoice approval',
            'target_entity_type' => PassiveInvoice::class,
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
        $approval = $node('approval', 'approval', ['approval_type' => 'and_parallel', 'assignee_type' => 'matrix_sequential', 'timeout_hours' => self::StepTimeoutHours]);
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
