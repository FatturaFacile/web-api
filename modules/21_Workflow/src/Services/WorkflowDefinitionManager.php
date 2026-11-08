<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

/**
 * Writes workflow definitions and their graph.
 *
 * Only a draft can change its nodes and transitions: running instances keep a snapshot of the published graph, and a
 * change to a published process is a new version (a new definition with the same code). Publishing validates the graph,
 * activates the definition and deactivates the other versions of the same code, which the engine then no longer starts.
 */
final class WorkflowDefinitionManager
{
    public function __construct(private readonly WorkflowGraphValidator $validator) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): WorkflowDefinition
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): WorkflowDefinition {
            $latestVersion = (int) WorkflowDefinition::withoutGlobalScopes()->withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('code', $data['code'])
                ->max('version');

            $definition = WorkflowDefinition::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'target_entity_type' => $data['target_entity_type'],
                'version' => $latestVersion + 1,
                'status' => 'draft',
                'is_active' => false,
                'configuration' => $data['configuration'] ?? [],
                'created_by' => $userId,
            ]);

            $nodeIds = [];
            foreach ($data['nodes'] ?? [] as $node) {
                $nodeIds[$node['node_key']] = $this->createNode($definition, $node)->id;
            }

            foreach ($data['transitions'] ?? [] as $transition) {
                $this->createTransition($definition, [
                    'from_node_id' => $nodeIds[$transition['from']],
                    'to_node_id' => $nodeIds[$transition['to']],
                    'action_trigger' => $transition['action_trigger'],
                    'priority' => $transition['priority'] ?? 1,
                    'condition_rules' => $transition['condition_rules'] ?? [],
                ]);
            }

            if ((bool) ($data['publish'] ?? false)) {
                $this->publish($definition);
            }

            return $definition;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(WorkflowDefinition $definition, array $data, ?string $userId): WorkflowDefinition
    {
        return DB::transaction(function () use ($definition, $data, $userId): WorkflowDefinition {
            if (array_key_exists('is_active', $data)) {
                if ((bool) $data['is_active'] && $definition->status !== 'published') {
                    throw ValidationException::withMessages(['is_active' => 'Publish the definition to activate it.']);
                }

                if ((bool) $data['is_active']) {
                    $this->deactivateOtherVersions($definition);
                }
            }

            $definition->update([...Arr::only($data, ['name', 'description', 'target_entity_type', 'configuration', 'is_active']), 'updated_by' => $userId]);

            return $definition;
        });
    }

    public function delete(WorkflowDefinition $definition): void
    {
        $hasRunningInstances = WorkflowInstance::withoutGlobalScopes()
            ->where('workflow_definition_id', $definition->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();

        if ($hasRunningInstances) {
            throw ValidationException::withMessages(['definition' => 'The definition has running instances: cancel them or deactivate the definition.']);
        }

        $definition->update(['is_active' => false, 'status' => 'archived']);
        $definition->delete();
    }

    public function publish(WorkflowDefinition $definition): WorkflowDefinition
    {
        $problems = $this->validator->problems($definition);

        if ($problems !== []) {
            throw ValidationException::withMessages(['graph' => $problems]);
        }

        return DB::transaction(function () use ($definition): WorkflowDefinition {
            $this->deactivateOtherVersions($definition);
            $definition->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);

            return $definition;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createNode(WorkflowDefinition $definition, array $data): WorkflowNode
    {
        $this->assertDraft($definition);

        return WorkflowNode::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $definition->tenant_id,
            'workflow_definition_id' => $definition->id,
            ...Arr::only($data, ['node_key', 'name', 'node_type', 'approval_type', 'assignee_type', 'assignee_id', 'timeout_hours', 'escalation_rule_id', 'ui_metadata']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateNode(WorkflowDefinition $definition, WorkflowNode $node, array $data): WorkflowNode
    {
        $this->assertDraft($definition);
        $node->update(Arr::only($data, ['node_key', 'name', 'node_type', 'approval_type', 'assignee_type', 'assignee_id', 'timeout_hours', 'escalation_rule_id', 'ui_metadata']));

        return $node;
    }

    /**
     * Removes the node and the transitions that touch it.
     */
    public function deleteNode(WorkflowDefinition $definition, WorkflowNode $node): void
    {
        $this->assertDraft($definition);

        DB::transaction(function () use ($node): void {
            WorkflowTransition::withoutGlobalScopes()
                ->where('from_node_id', $node->id)
                ->orWhere('to_node_id', $node->id)
                ->forceDelete();
            $node->forceDelete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTransition(WorkflowDefinition $definition, array $data): WorkflowTransition
    {
        $this->assertDraft($definition);

        return WorkflowTransition::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $definition->tenant_id,
            'workflow_definition_id' => $definition->id,
            'priority' => 1,
            ...Arr::only($data, ['from_node_id', 'to_node_id', 'action_trigger', 'priority', 'condition_rules']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTransition(WorkflowDefinition $definition, WorkflowTransition $transition, array $data): WorkflowTransition
    {
        $this->assertDraft($definition);
        $transition->update(Arr::only($data, ['from_node_id', 'to_node_id', 'action_trigger', 'priority', 'condition_rules']));

        return $transition;
    }

    public function deleteTransition(WorkflowDefinition $definition, WorkflowTransition $transition): void
    {
        $this->assertDraft($definition);
        $transition->forceDelete();
    }

    private function assertDraft(WorkflowDefinition $definition): void
    {
        if ($definition->status !== 'draft') {
            throw ValidationException::withMessages(['definition' => 'Only a draft definition can change its graph: create a new version.']);
        }
    }

    private function deactivateOtherVersions(WorkflowDefinition $definition): void
    {
        WorkflowDefinition::withoutGlobalScopes()
            ->where('tenant_id', $definition->tenant_id)
            ->where('code', $definition->code)
            ->whereKeyNot($definition->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
