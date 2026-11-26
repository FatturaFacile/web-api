<?php

declare(strict_types=1);

namespace Modules\Documents\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Documents\Enums\ApprovalStatus;
use Modules\Documents\Models\DmsApprovalRequest;
use Modules\Documents\Models\DmsApprovalTemplate;
use Modules\Documents\Models\DmsApprovalTemplateStep;
use Modules\Documents\Models\DmsDocument;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Enums\ApprovalOutcome;
use Modules\Workflow\Enums\InstanceStatus;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;
use Throwable;

/**
 * Converges the DMS approval requests onto the unified workflow engine (`dms_document` subject type, Mod 006).
 *
 * A DMS approval template is a sequence of steps; each request now runs as an instance of a workflow generated from the
 * template (one approval node per step), so the inbox, the delegations, the SLA clocks, the audit trail and the evidence
 * PDF cover documents too. The `DmsApprovalRequest` rows stay as the read model of the DMS screens and mirror the
 * instance: the bridge never lets an engine problem block the document flow (it is reported and the legacy path goes on).
 */
final readonly class DocumentApprovalWorkflowBridge
{
    /**
     * Inject the engine.
     */
    public function __construct(private WorkflowEngineInterface $engine) {}

    /**
     * Starts the instance of a new request and links it to the request. Returns null when the engine is off or failed.
     */
    public function start(DmsApprovalRequest $request, DmsApprovalTemplate $template, DmsDocument $document, User $requester): ?WorkflowInstance
    {
        if (! (bool) config('workflow.dms_via_engine', true)) {
            return null;
        }

        try {
            $code = $this->ensureDefinition($template);

            $instance = $this->engine->start(new StartWorkflowDTO(
                workflowCode: $code,
                approvable: $document,
                // Whoever asked for the approval can never decide on it.
                contextSnapshot: ['four_eyes' => true, 'maker_user_ids' => [(string) $requester->id], 'document_type' => $document->document_type],
                tenantId: (string) $document->tenant_id,
                companyId: null,
                initiatorUserId: (string) $requester->id,
            ));

            $request->update(['workflow_instance_id' => $instance->id]);

            return $instance;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Records the decision of the user on the pending step of the request's instance, when it has one.
     */
    public function decide(DmsApprovalRequest $request, User $user, bool $approve, ?string $comment): void
    {
        $instance = $this->activeInstanceOf($request);

        if ($instance === null) {
            return;
        }

        try {
            $step = $instance->steps()->where('status', 'pending')->orderBy('created_at')->first();

            if ($step === null) {
                return;
            }

            $this->engine->recordDecision(new RecordDecisionDTO(
                workflowInstanceStepId: (string) $step->id,
                decision: $approve ? 'approved' : 'rejected',
                userId: (string) $user->id,
                comment: $comment,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Closes the instance of a request that ended on the DMS side (cancelled or decided by the legacy path).
     */
    public function close(DmsApprovalRequest $request, string $reason): void
    {
        $instance = $this->activeInstanceOf($request);

        if ($instance === null) {
            return;
        }

        try {
            $this->engine->cancel($instance, $reason);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Mirrors the end of an instance on the request of the document that is still pending.
     */
    public function applyOutcome(DmsDocument $document, ApprovalOutcome $outcome): void
    {
        $status = match ($outcome) {
            ApprovalOutcome::Approved => ApprovalStatus::Approved,
            ApprovalOutcome::Rejected, ApprovalOutcome::ChangesRequested => ApprovalStatus::Rejected,
            default => ApprovalStatus::Cancelled,
        };

        DmsApprovalRequest::query()
            ->where('document_id', $document->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('workflow_instance_id')
            ->update(['status' => $status->value, 'completed_at' => now()]);
    }

    private function activeInstanceOf(DmsApprovalRequest $request): ?WorkflowInstance
    {
        if ($request->workflow_instance_id === null) {
            return null;
        }

        return WorkflowInstance::query()->whereKey($request->workflow_instance_id)->whereIn('status', InstanceStatus::active())->first();
    }

    /**
     * The workflow generated from a template: one approval node per step, in order. A template that changed gets a new
     * version of the definition (the previous one stays for the instances that run on it).
     */
    private function ensureDefinition(DmsApprovalTemplate $template): string
    {
        $steps = DmsApprovalTemplateStep::query()->where('template_id', $template->id)->orderBy('position')->get();
        $signature = hash('sha256', $steps->map(static fn (DmsApprovalTemplateStep $step): string => implode('|', [$step->position, $step->name, $step->approver_type, $step->approver_user_id, $step->approver_role]))->implode(';'));
        $code = 'DMS_TPL_'.substr(str_replace('-', '', (string) $template->id), 0, 24);
        $tenantId = (string) $template->tenant_id;

        $current = WorkflowDefinition::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->where('is_active', true)->first();

        if ($current !== null && ($current->configuration['signature'] ?? null) === $signature) {
            return $code;
        }

        DB::transaction(function () use ($steps, $signature, $code, $tenantId, $template, $current): void {
            $version = (int) WorkflowDefinition::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->where('code', $code)->max('version') + 1;

            $definition = WorkflowDefinition::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $template->name,
                'description' => 'Generated from the DMS approval template.',
                'target_entity_type' => 'dms_document',
                'version' => $version,
                'status' => 'published',
                'is_active' => false,
                'configuration' => ['signature' => $signature, 'generated_from' => 'dms_approval_template'],
                'published_at' => now(),
            ]);

            $make = static fn (string $key, string $name, string $type, array $extra = []): WorkflowNode => WorkflowNode::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'workflow_definition_id' => $definition->id,
                'node_key' => $key,
                'name' => $name,
                'node_type' => $type,
                ...$extra,
            ]);

            $previous = $make('start', 'Start', 'start');
            $trigger = 'start';

            foreach ($steps as $step) {
                $node = $make('step_'.$step->position, $step->name, 'approval', [
                    'approval_type' => 'single',
                    'assignee_type' => $step->approver_type === 'user' ? 'direct_user' : 'role',
                    'assignee_id' => $step->approver_type === 'user' ? $step->approver_user_id : $step->approver_role,
                    'timeout_hours' => 72,
                ]);

                $this->link($definition, $previous, $node, $trigger);
                $previous = $node;
                $trigger = 'approved';
            }

            $end = $make('end', 'Approved', 'end');
            $this->link($definition, $previous, $end, $trigger);

            if ($current !== null) {
                $current->update(['is_active' => false]);
            }

            $definition->update(['is_active' => true]);
        });

        return $code;
    }

    private function link(WorkflowDefinition $definition, WorkflowNode $from, WorkflowNode $to, string $trigger): void
    {
        WorkflowTransition::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $definition->tenant_id,
            'workflow_definition_id' => $definition->id,
            'from_node_id' => $from->id,
            'to_node_id' => $to->id,
            'action_trigger' => $trigger,
            'priority' => 1,
        ]);
    }
}
