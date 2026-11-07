<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Support\Collection;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

/**
 * Checks that a definition can run on the engine before it is published:
 *
 * - exactly one start node and at least one end node;
 * - the start node leaves through a "start" (or "*") transition;
 * - every approval node has an assignee and leaves through an "approved" (or "*") transition; without a "rejected"
 *   transition a rejection ends the instance as rejected;
 * - end nodes have no outgoing transitions;
 * - every node is reachable from the start node and an end node is reachable;
 * - transitions join nodes of the same definition.
 */
final class WorkflowGraphValidator
{
    public const array NodeTypes = ['start', 'approval', 'conditional', 'notification', 'end'];

    public const array ApprovalTypes = ['single', 'and_parallel', 'or_parallel', 'majority', 'supermajority', 'unanimous'];

    public const array AssigneeTypes = ['direct_user', 'role', 'reporting_manager', 'matrix_lookup', 'matrix_sequential'];

    public const array ActionTriggers = ['start', 'approved', 'rejected', 'timeout', 'condition_true', 'condition_false', '*'];

    /**
     * @return list<string> the problems found; empty when the graph is valid
     */
    public function problems(WorkflowDefinition $definition): array
    {
        /** @var Collection<int, WorkflowNode> $nodes */
        $nodes = WorkflowNode::withoutGlobalScopes()->where('workflow_definition_id', $definition->id)->get()->keyBy('id');
        /** @var Collection<int, WorkflowTransition> $transitions */
        $transitions = WorkflowTransition::withoutGlobalScopes()->where('workflow_definition_id', $definition->id)->get();

        $problems = [];
        $starts = $nodes->where('node_type', 'start');
        $ends = $nodes->where('node_type', 'end');

        if ($starts->count() !== 1) {
            $problems[] = 'The definition needs exactly one start node.';
        }

        if ($ends->isEmpty()) {
            $problems[] = 'The definition needs at least one end node.';
        }

        foreach ($transitions as $transition) {
            if (! $nodes->has($transition->from_node_id) || ! $nodes->has($transition->to_node_id)) {
                $problems[] = "Transition {$transition->id} joins a node of another definition.";
            }
        }

        $outgoing = $transitions->groupBy('from_node_id');
        $triggersOf = fn (WorkflowNode $node): array => ($outgoing->get($node->id) ?? collect())->pluck('action_trigger')->all();

        foreach ($nodes as $node) {
            $triggers = $triggersOf($node);

            if ($node->node_type === 'start' && array_intersect($triggers, ['start', '*']) === []) {
                $problems[] = "The start node [{$node->node_key}] needs a \"start\" transition.";
            }

            if ($node->node_type === 'approval') {
                if (empty($node->assignee_type) || (empty($node->assignee_id) && ! in_array($node->assignee_type, ['reporting_manager', 'matrix_lookup', 'matrix_sequential'], true))) {
                    $problems[] = "The approval node [{$node->node_key}] needs an assignee.";
                }

                if (array_intersect($triggers, ['approved', '*']) === []) {
                    $problems[] = "The approval node [{$node->node_key}] needs an \"approved\" transition.";
                }
            }

            if ($node->node_type === 'end' && $triggers !== []) {
                $problems[] = "The end node [{$node->node_key}] cannot have outgoing transitions.";
            }
        }

        if ($starts->count() === 1) {
            $reached = $this->reachableFrom((string) $starts->first()->id, $outgoing);

            foreach ($nodes as $node) {
                if (! isset($reached[$node->id])) {
                    $problems[] = "The node [{$node->node_key}] cannot be reached from the start node.";
                }
            }

            if ($ends->isNotEmpty() && $ends->every(fn (WorkflowNode $end): bool => ! isset($reached[$end->id]))) {
                $problems[] = 'No end node can be reached from the start node.';
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  Collection<string, Collection<int, WorkflowTransition>>  $outgoing
     * @return array<string, true>
     */
    private function reachableFrom(string $startId, Collection $outgoing): array
    {
        $reached = [$startId => true];
        $queue = [$startId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($outgoing->get($current) ?? [] as $transition) {
                if (! isset($reached[$transition->to_node_id])) {
                    $reached[$transition->to_node_id] = true;
                    $queue[] = (string) $transition->to_node_id;
                }
            }
        }

        return $reached;
    }
}
