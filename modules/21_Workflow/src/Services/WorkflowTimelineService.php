<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\ApprovalToken;
use Modules\Workflow\Models\WorkflowAuditEntry;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * The story of one approval for the timeline of the document and for the "Evidenza di approvazione": one line per step
 * (who had to decide, when, what they decided, through which channel, with which reason) and one per lifecycle event
 * (started, reassigned, withdrawn, cancelled, blocked, changes requested). Everything is read inside the tenant of the
 * instance and in time order.
 */
final readonly class WorkflowTimelineService
{
    /**
     * Audit events that are part of the story next to the steps.
     *
     * @var list<string>
     */
    private const array Events = ['instance_started', 'step_reassigned', 'step_reassigned_removed_approver', 'instance_withdrawn', 'instance_cancelled', 'instance_cancelled_by_admin', 'instance_blocked', 'changes_requested', 'approval_invalidated', 'instance_expired'];

    /**
     * @return list<array{id: string, type: string, node_name: ?string, status: string, actor: ?string, decided_at: ?string, due_at: ?string, comment: ?string, channel: ?string, at: string}>
     */
    public function timeline(WorkflowInstance $instance): array
    {
        $items = [];

        $steps = WorkflowInstanceStep::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('tenant_id', $instance->tenant_id)
            ->with(['node', 'decisions'])
            ->orderBy('created_at')
            ->get();

        $names = User::query()->whereIn('id', $this->userIds($steps, $instance))->pluck('name', 'id');

        foreach ($steps as $step) {
            /** @var ApprovalDecision|null $decision */
            $decision = $step->decisions->sortBy('decided_at')->first();
            $actorId = $decision !== null ? $decision->effective_user_id : $step->assigned_user_id;
            $system = $decision !== null && (string) $decision->user_id === WorkflowEngineService::SystemActor;
            $at = ($decision->decided_at ?? $step->completed_at ?? $step->created_at)?->toIso8601String() ?? now()->toIso8601String();

            $items[] = [
                'id' => (string) $step->id,
                'type' => 'step',
                'node_name' => $step->node?->name,
                'status' => (string) $step->status,
                'actor' => $system ? 'system' : ($actorId !== null ? (string) ($names[(string) $actorId] ?? $actorId) : ($step->assigned_role !== null ? 'role:'.$step->assigned_role : null)),
                'decided_at' => $decision?->decided_at?->toIso8601String(),
                'due_at' => $step->due_at?->toIso8601String(),
                'comment' => $decision?->comment,
                'channel' => $decision === null ? null : ($decision->approval_token_id !== null ? $this->channelOf($decision) : 'web'),
                'at' => $at,
            ];
        }

        $events = WorkflowAuditEntry::query()
            ->where('workflow_instance_id', $instance->id)
            ->where('tenant_id', $instance->tenant_id)
            ->whereIn('event_type', self::Events)
            ->orderBy('created_at')
            ->get();

        foreach ($events as $event) {
            /** @var array<string, mixed> $payload */
            $payload = (array) $event->event_payload;
            $items[] = [
                'id' => (string) $event->id,
                'type' => 'event',
                'node_name' => null,
                'status' => (string) $event->event_type,
                'actor' => $event->actor_user_id !== null ? (string) ($names[(string) $event->actor_user_id] ?? $event->actor_user_id) : null,
                'decided_at' => null,
                'due_at' => null,
                'comment' => isset($payload['reason']) ? (string) $payload['reason'] : ($payload['comment'] ?? null),
                'channel' => null,
                'at' => $event->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return $items;
    }

    private function channelOf(ApprovalDecision $decision): string
    {
        $channel = ApprovalToken::query()->whereKey($decision->approval_token_id)->value('channel');

        return (string) ($channel ?? 'email');
    }

    /**
     * @param  Collection<int, WorkflowInstanceStep>  $steps
     * @return list<string>
     */
    private function userIds($steps, WorkflowInstance $instance): array
    {
        $ids = [];

        foreach ($steps as $step) {
            $ids[] = $step->assigned_user_id;

            foreach ($step->decisions as $decision) {
                $ids[] = $decision->effective_user_id;
            }
        }

        $ids[] = $instance->created_by;
        $ids = [...$ids, ...WorkflowAuditEntry::query()->where('workflow_instance_id', $instance->id)->whereNotNull('actor_user_id')->pluck('actor_user_id')->all()];

        return array_values(array_unique(array_filter(array_map(static fn (mixed $id): string => (string) $id, $ids), static fn (string $id): bool => $id !== '' && $id !== WorkflowEngineService::SystemActor)));
    }
}
