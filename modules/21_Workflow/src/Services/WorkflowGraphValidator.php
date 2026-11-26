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
 * - transitions join nodes of the same definition;
 * - a parallel split has at least two branches, every branch is an approval node, and all branches meet in the same
 *   parallel join, which in turn has at least two incoming branches (no orphan join);
 * - there is no cycle (a "rejected" or "timeout" path may lead back: that is a loop of the process, not a cycle of the graph).
 */
final class WorkflowGraphValidator
{
    public const array NodeTypes = ['start', 'approval', 'condition', 'conditional', 'parallel_split', 'parallel_join', 'notification', 'end'];

    public const array ApprovalTypes = ['single', 'and_parallel', 'or_parallel', 'majority', 'supermajority', 'unanimous'];

    /**
     * `user_list` approvers decide in parallel (the voting rule of the node applies), `user_list_sequential` one after the
     * other in the order of the list; `assignee_id` holds their ids, comma separated.
     */
    public const array AssigneeTypes = ['direct_user', 'role', 'reporting_manager', 'matrix_lookup', 'matrix_sequential', 'user_list', 'user_list_sequential'];

    public const array ActionTriggers = ['start', 'approved', 'rejected', 'timeout', 'condition_true', 'condition_false', '*'];

    /**
     * @return list<string> the problems found; empty when the graph is valid
     */
    public function problems(WorkflowDefinition $definition): array
    {
        /** @var Collection<string, WorkflowNode> $nodes */
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

                if (in_array($node->assignee_type, ['user_list', 'user_list_sequential'], true) && app(WorkflowAssigneeResolver::class)->userList($node->assignee_id) === []) {
                    $problems[] = "The approval node [{$node->node_key}] needs at least one valid user id in its list.";
                }

                if (array_intersect($triggers, ['approved', '*']) === []) {
                    $problems[] = "The approval node [{$node->node_key}] needs an \"approved\" transition.";
                }
            }

            if ($node->node_type === 'end' && $triggers !== []) {
                $problems[] = "The end node [{$node->node_key}] cannot have outgoing transitions.";
            }
        }

        $incoming = $transitions->groupBy('to_node_id');

        foreach ($nodes as $node) {
            $branches = $outgoing->get($node->id) ?? collect();

            if ($node->node_type === 'parallel_split') {
                if ($branches->count() < 2) {
                    $problems[] = "The parallel split [{$node->node_key}] needs at least two branches.";
                }

                $joins = [];

                foreach ($branches as $branch) {
                    $target = $nodes->get($branch->to_node_id);

                    if ($target === null || $target->node_type !== 'approval') {
                        $problems[] = "Every branch of the parallel split [{$node->node_key}] must lead to an approval node.";

                        continue;
                    }

                    $exit = ($outgoing->get($target->id) ?? collect())->first(fn (WorkflowTransition $transition): bool => in_array($transition->action_trigger, ['approved', '*'], true));
                    $joins[(string) ($exit->to_node_id ?? '')] = true;
                }

                $joinIds = array_keys($joins);

                if ($joinIds !== [] && (count($joinIds) !== 1 || $nodes->get($joinIds[0])?->node_type !== 'parallel_join')) {
                    $problems[] = "The branches of the parallel split [{$node->node_key}] must meet in one parallel join.";
                }
            }

            if ($node->node_type === 'parallel_join' && ($incoming->get($node->id) ?? collect())->count() < 2) {
                $problems[] = "The parallel join [{$node->node_key}] needs at least two incoming branches.";
            }
        }

        if ($this->hasCycle(array_map('strval', $nodes->keys()->all()), $transitions)) {
            $problems[] = 'The graph contains a cycle.';
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
     * Depth-first search for a cycle over the forward transitions (the "rejected" and "timeout" paths may loop back).
     *
     * @param  list<string>  $nodeIds
     * @param  Collection<int, WorkflowTransition>  $transitions
     */
    private function hasCycle(array $nodeIds, Collection $transitions): bool
    {
        $edges = [];

        foreach ($transitions as $transition) {
            if (! in_array($transition->action_trigger, ['rejected', 'timeout'], true)) {
                $edges[(string) $transition->from_node_id][] = (string) $transition->to_node_id;
            }
        }

        $state = [];
        $visit = function (string $node) use (&$visit, &$state, $edges): bool {
            $state[$node] = 1;

            foreach ($edges[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1 || (($state[$next] ?? 0) === 0 && $visit($next))) {
                    return true;
                }
            }

            $state[$node] = 2;

            return false;
        };

        foreach ($nodeIds as $id) {
            if (($state[(string) $id] ?? 0) === 0 && $visit((string) $id)) {
                return true;
            }
        }

        return false;
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
