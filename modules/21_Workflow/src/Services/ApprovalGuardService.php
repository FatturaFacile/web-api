<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Workflow\Enums\DecisionType;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Control rules applied before a decision is stored (Mod 097 segregation of duties, Mod 004 signing limit):
 *
 * - the requester cannot approve their own request; a step assigned to the requester is handed to their manager or to
 *   the administrators instead;
 * - the same user cannot approve two levels of one workflow;
 * - an approver decides only up to the `approval_limit` of their membership of the tenant (functional currency).
 *
 * The two segregation flags come from the definition (`configuration.sod`) and fall back to `config('workflow.sod')`.
 * The engine's own actor is never blocked: automatic decisions are audited with the `system` actor.
 */
final readonly class ApprovalGuardService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ApprovableRegistry $registry, private WorkflowAssigneeResolver $assignees) {}

    /**
     * The segregation flags in force for an instance.
     *
     * @return array{self_approval: bool, multi_level: bool}
     */
    public function segregationOf(WorkflowInstance $instance): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = (array) $instance->workflow_definition_snapshot;
        $configuration = (array) ($snapshot['configuration'] ?? []);
        $definition = (array) ($configuration['sod'] ?? []);

        return [
            'self_approval' => (bool) ($definition['block_self_approval'] ?? config('workflow.sod.block_self_approval', false)),
            'multi_level' => (bool) ($definition['block_multi_level'] ?? config('workflow.sod.block_multi_level', false)),
        ];
    }

    /**
     * The user who asked for the approval.
     */
    public function requesterOf(WorkflowInstance $instance): ?string
    {
        $approvable = $instance->approvable;
        $requester = $approvable === null ? null : $this->registry->requesterOf($approvable);

        return $requester ?? ($instance->created_by === null ? null : (string) $instance->created_by);
    }

    /**
     * The amount the thresholds are compared with, in the functional currency; null when the document has no amount.
     */
    public function amountOf(WorkflowInstance $instance): ?float
    {
        $approvable = $instance->approvable;

        if ($approvable === null) {
            return null;
        }

        $attributes = $this->registry->attributesOf($approvable);
        $amount = $attributes['amount_functional'] ?? $attributes['amount'] ?? null;

        return is_numeric($amount) ? (float) $amount : null;
    }

    /**
     * The signing limit of a member of the tenant; null means unlimited or not configured.
     */
    public function approvalLimitOf(string $userId, string $tenantId): ?float
    {
        static $hasColumn = null;
        $hasColumn ??= Schema::hasColumn('tenant_user', 'approval_limit');

        if (! $hasColumn) {
            return null;
        }

        $limit = DB::table('tenant_user')->where('user_id', $userId)->where('tenant_id', $tenantId)->value('approval_limit');

        return $limit === null ? null : (float) $limit;
    }

    /**
     * Refuses a decision that breaks a control rule.
     *
     * @throws AuthorizationException
     */
    public function assertMayDecide(WorkflowInstance $instance, WorkflowInstanceStep $step, string $userId, string $effectiveUserId, DecisionType $decision): void
    {
        if ($userId === WorkflowEngineService::SystemActor) {
            return;
        }

        $flags = $this->segregationOf($instance);
        $actors = [$userId, $effectiveUserId];

        if ($flags['self_approval'] && $decision === DecisionType::Approve) {
            $requester = $this->requesterOf($instance);

            if ($requester !== null && in_array($requester, $actors, true)) {
                throw new AuthorizationException(__('workflow_errors.control.self_approval'));
            }
        }

        if ($flags['multi_level'] && $decision === DecisionType::Approve) {
            $alreadyApproved = ApprovalDecision::query()
                ->whereIn('workflow_instance_step_id', $instance->steps()->where('id', '!=', $step->id)->pluck('id'))
                ->where('decision', DecisionType::Approve->value)
                ->where(fn ($query) => $query->whereIn('user_id', $actors)->orWhereIn('effective_user_id', $actors))
                ->exists();

            if ($alreadyApproved) {
                throw new AuthorizationException(__('workflow_errors.control.multi_level'));
            }
        }

        if ($decision === DecisionType::Approve) {
            $limit = $this->approvalLimitOf($effectiveUserId, (string) $instance->tenant_id);
            $amount = $this->amountOf($instance);

            if ($limit !== null && $amount !== null && $amount > $limit) {
                throw new AuthorizationException(__('workflow_errors.control.signing_limit', ['limit' => number_format($limit, 2, '.', "'")]));
            }
        }
    }

    /**
     * A level that would be decided by the requester is handed to the requester's manager, or to the administrators when
     * there is none, so the request never waits on the person who made it.
     *
     * @return array{0: ?string, 1: ?string} the user and role the step is assigned to
     */
    public function substituteRequester(WorkflowInstance $instance, ?string $userId, ?string $role): array
    {
        if ($userId === null || ! $this->segregationOf($instance)['self_approval'] || $userId !== $this->requesterOf($instance)) {
            return [$userId, $role];
        }

        $manager = $this->assignees->managerUserIdOf($userId, (string) $instance->tenant_id);

        return $manager !== null && $manager !== $userId ? [$manager, null] : [null, 'admin'];
    }
}
