<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Modules\Projects\Wbs\CriticalPathCalculator;
use Modules\Projects\Wbs\Enums\WbsNodeType;
use Modules\Projects\Wbs\WbsCodeIndexer;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Commands on the multilevel WBS of a project: nodes (insert, edit, move, delete, reorder) and their dependencies.
 *
 * Every command runs in one transaction on the locked project and ends by renumbering the WBS codes (1, 1.1, 1.1.1),
 * recomputing the critical path and rolling the values up, so the stored tree is always consistent. The structure is
 * a tree of any depth; milestones are leaves; dependencies link leaf nodes only and never close a cycle.
 */
final readonly class ProjectWbsService
{
    private const array NodeFields = [
        'title', 'node_type', 'status', 'required_role', 'planned_hours', 'actual_hours', 'planned_cost', 'actual_cost',
        'planned_revenue', 'start_date', 'end_date', 'percent_complete',
    ];

    public function __construct(
        private ProjectWbsScheduleService $schedule,
        private WbsCodeIndexer $indexer,
        private CriticalPathCalculator $cpm,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated node attributes (`parent_node_id`, `sort_order` position, values)
     *
     * @throws ConflictHttpException when the project is closed or the parent cannot take children
     * @throws ValidationException when the parent or the dates are invalid
     */
    public function createNode(Project $project, array $attributes, string $actorUserId): ProjectWbsNode
    {
        return DB::transaction(function () use ($project, $attributes, $actorUserId): ProjectWbsNode {
            $locked = $this->lockOpen($project);
            $parentId = $attributes['parent_node_id'] ?? null;

            if ($parentId !== null) {
                $this->assertCanHaveChildren($locked, (string) $parentId);
            }

            $values = $this->nodeValues($attributes);
            $this->assertDates($values);

            $siblings = ProjectWbsNode::query()->where('project_id', $locked->id)->where('parent_node_id', $parentId);
            $position = isset($attributes['sort_order'])
                ? (int) $attributes['sort_order']
                : ((int) (clone $siblings)->max('sort_order')) + 1;

            if (isset($attributes['sort_order'])) {
                (clone $siblings)->where('sort_order', '>=', $position)->increment('sort_order');
            }

            $node = ProjectWbsNode::create([
                ...$values,
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'project_id' => $locked->id,
                'parent_node_id' => $parentId,
                'sort_order' => $position,
                'node_type' => $values['node_type'] ?? WbsNodeType::WorkPackage->value,
                'status' => $values['status'] ?? 'todo',
                'wbs_code' => '',
            ]);

            $this->refresh($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_node_created', "WBS node {$node->title} created", [], ['node_id' => $node->id, 'parent_node_id' => $parentId], $node);

            return $node->refresh();
        });
    }

    /**
     * Edits a node; a `parent_node_id` / `sort_order` in the attributes moves it.
     *
     * @param  array<string, mixed>  $attributes  validated, only the keys to change
     *
     * @throws ConflictHttpException when the project is closed or the new parent cannot take children
     * @throws ValidationException when the move would put a node below itself or the dates are invalid
     */
    public function updateNode(Project $project, ProjectWbsNode $node, array $attributes, string $actorUserId): ProjectWbsNode
    {
        return DB::transaction(function () use ($project, $node, $attributes, $actorUserId): ProjectWbsNode {
            $locked = $this->lockOpen($project);
            $current = $this->ownNode($locked, $node->id);
            $before = $current->only(['title', 'parent_node_id', 'start_date', 'end_date', 'planned_hours', 'planned_cost']);

            $values = $this->nodeValues($attributes);
            $merged = [
                'node_type' => $values['node_type'] ?? $current->node_type,
                'start_date' => array_key_exists('start_date', $values) ? $values['start_date'] : $current->start_date?->toDateString(),
                'end_date' => array_key_exists('end_date', $values) ? $values['end_date'] : $current->end_date?->toDateString(),
            ];
            $this->assertDates($merged);

            if (($merged['node_type'] === WbsNodeType::Milestone->value) && $current->children()->exists()) {
                throw ValidationException::withMessages(['node_type' => __('projects_errors.wbs.milestone_cannot_have_children')]);
            }

            if (array_key_exists('parent_node_id', $attributes) || array_key_exists('sort_order', $attributes)) {
                $parentId = array_key_exists('parent_node_id', $attributes) ? $attributes['parent_node_id'] : $current->parent_node_id;
                $this->move($locked, $current, $parentId, isset($attributes['sort_order']) ? (int) $attributes['sort_order'] : null);
            }

            $current->update($values);

            $this->refresh($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_node_updated', "WBS node {$current->title} updated", $before, $current->refresh()->only(['title', 'parent_node_id', 'start_date', 'end_date', 'planned_hours', 'planned_cost']), $current);

            return $current;
        });
    }

    /**
     * Deletes a node with its whole subtree and the dependencies that touch it.
     *
     * @throws ConflictHttpException when the project is closed or hours or cost are already recorded below the node
     */
    public function deleteNode(Project $project, ProjectWbsNode $node, string $actorUserId): void
    {
        DB::transaction(function () use ($project, $node, $actorUserId): void {
            $locked = $this->lockOpen($project);
            $current = $this->ownNode($locked, $node->id);
            $nodes = $this->projectNodes($locked);
            $subtree = $this->subtreeIds($nodes, $current->id);

            $actuals = $nodes->whereIn('id', $subtree)->filter(
                static fn (ProjectWbsNode $candidate): bool => $nodes->where('parent_node_id', $candidate->id)->isEmpty()
                    && (Money::isPositive($candidate->actual_hours) || Money::isPositive($candidate->actual_cost)),
            );

            if ($actuals->isNotEmpty()) {
                throw new ConflictHttpException(__('projects_errors.wbs.node_has_actuals'));
            }

            ProjectWbsDependency::query()
                ->where('project_id', $locked->id)
                ->where(fn ($query) => $query->whereIn('predecessor_node_id', $subtree)->orWhereIn('successor_node_id', $subtree))
                ->forceDelete();
            ProjectWbsNode::query()->whereIn('id', $subtree)->forceDelete();

            $this->refresh($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_node_deleted', "WBS node {$current->title} deleted with ".(count($subtree) - 1).' descendants', ['node_id' => $current->id], [], $current);
        });
    }

    /**
     * Applies several moves at once: every item gives the node, its new parent (null = top level) and position.
     *
     * @param  list<array{id: string, parent_node_id?: string|null, sort_order?: int}>  $moves
     * @return Collection<int, ProjectWbsNode> the nodes of the project in WBS order
     *
     * @throws ConflictHttpException when the project is closed
     * @throws ValidationException when a node does not belong to the project or the result would not be a tree
     */
    public function reorder(Project $project, array $moves, string $actorUserId): Collection
    {
        return DB::transaction(function () use ($project, $moves, $actorUserId): Collection {
            $locked = $this->lockOpen($project);

            foreach ($moves as $move) {
                $node = $this->ownNode($locked, $move['id']);
                $parentId = array_key_exists('parent_node_id', $move) ? $move['parent_node_id'] : $node->parent_node_id;

                if ($parentId === $node->parent_node_id && ! isset($move['sort_order'])) {
                    continue;
                }

                $this->move($locked, $node, $parentId, isset($move['sort_order']) ? (int) $move['sort_order'] : null);
            }

            $this->assertAcyclicTree($this->projectNodes($locked));
            $this->refresh($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_reordered', 'WBS reordered ('.count($moves).' moves)', [], ['moves' => $moves]);

            return $this->projectNodes($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  `predecessor_node_id`, `successor_node_id`, `dependency_type`, `lag_days`
     *
     * @throws ValidationException when a node is foreign, a summary node, the link exists or would close a cycle
     */
    public function createDependency(Project $project, array $attributes, string $actorUserId): ProjectWbsDependency
    {
        return DB::transaction(function () use ($project, $attributes, $actorUserId): ProjectWbsDependency {
            $locked = $this->lockOpen($project);
            $predecessor = (string) $attributes['predecessor_node_id'];
            $successor = (string) $attributes['successor_node_id'];

            foreach (['predecessor_node_id' => $predecessor, 'successor_node_id' => $successor] as $field => $id) {
                $this->assertLeaf($locked, $id, $field);
            }

            $links = ProjectWbsDependency::query()->where('project_id', $locked->id)->get(['predecessor_node_id', 'successor_node_id']);

            if ($links->contains(fn (ProjectWbsDependency $link): bool => $link->predecessor_node_id === $predecessor && $link->successor_node_id === $successor)) {
                throw ValidationException::withMessages(['successor_node_id' => __('projects_errors.wbs.dependency_duplicate')]);
            }

            $pairs = $links->map(static fn (ProjectWbsDependency $link): array => ['predecessor' => $link->predecessor_node_id, 'successor' => $link->successor_node_id])->all();

            if ($this->cpm->wouldCreateCycle($pairs, $predecessor, $successor)) {
                throw ValidationException::withMessages(['successor_node_id' => __('projects_errors.wbs.dependency_cycle')]);
            }

            $dependency = ProjectWbsDependency::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'project_id' => $locked->id,
                'predecessor_node_id' => $predecessor,
                'successor_node_id' => $successor,
                'dependency_type' => $attributes['dependency_type'] ?? 'FS',
                'lag_days' => (int) ($attributes['lag_days'] ?? 0),
            ]);

            $this->schedule->recalculate($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_dependency_created', 'WBS dependency created', [], ['dependency_id' => $dependency->id, 'type' => $dependency->dependency_type, 'lag_days' => $dependency->lag_days], $dependency);

            return $dependency->refresh();
        });
    }

    /**
     * Changes type and lag of a link (its two ends stay: delete and create to relink).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateDependency(Project $project, ProjectWbsDependency $dependency, array $attributes, string $actorUserId): ProjectWbsDependency
    {
        return DB::transaction(function () use ($project, $dependency, $attributes, $actorUserId): ProjectWbsDependency {
            $locked = $this->lockOpen($project);
            $current = $this->ownDependency($locked, $dependency->id);
            $before = $current->only(['dependency_type', 'lag_days']);

            $current->update(array_filter([
                'dependency_type' => $attributes['dependency_type'] ?? null,
                'lag_days' => isset($attributes['lag_days']) ? (int) $attributes['lag_days'] : null,
            ], static fn (mixed $value): bool => $value !== null));

            $this->schedule->recalculate($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_dependency_updated', 'WBS dependency updated', $before, $current->only(['dependency_type', 'lag_days']), $current);

            return $current->refresh();
        });
    }

    public function deleteDependency(Project $project, ProjectWbsDependency $dependency, string $actorUserId): void
    {
        DB::transaction(function () use ($project, $dependency, $actorUserId): void {
            $locked = $this->lockOpen($project);
            $current = $this->ownDependency($locked, $dependency->id);

            $current->forceDelete();

            $this->schedule->recalculate($locked);
            $this->audit->record($locked, $actorUserId, 'wbs_dependency_deleted', 'WBS dependency deleted', ['dependency_id' => $current->id], [], $current);
        });
    }

    /**
     * Renumbers the WBS codes of the whole tree and recomputes schedule and roll-up.
     */
    public function refresh(Project $project): void
    {
        $this->reindex($project);
        $this->schedule->recalculate($project);
    }

    /**
     * Gives every node its dotted code and a gapless position among its siblings.
     */
    public function reindex(Project $project): void
    {
        $nodes = $this->projectNodes($project);
        $input = $nodes->map(static fn (ProjectWbsNode $node): array => [
            'id' => $node->id,
            'parent_id' => $node->parent_node_id,
            'sort_order' => (int) $node->sort_order,
        ])->values()->all();

        foreach ($this->indexer->index($input) as $id => $row) {
            $node = $nodes->firstWhere('id', $id);

            if ($node !== null && ($node->wbs_code !== $row['code'] || (int) $node->sort_order !== $row['sort_order'])) {
                $node->update(['wbs_code' => $row['code'], 'sort_order' => $row['sort_order']]);
            }
        }
    }

    private function lockOpen(Project $project): Project
    {
        $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw new ConflictHttpException(__('projects_errors.project.no_longer_changeable', ['status' => $locked->status]));
        }

        return $locked;
    }

    /**
     * @return Collection<int, ProjectWbsNode>
     */
    private function projectNodes(Project $project): Collection
    {
        return ProjectWbsNode::query()->where('project_id', $project->id)->orderBy('sort_order')->get();
    }

    private function ownNode(Project $project, string $nodeId): ProjectWbsNode
    {
        $node = ProjectWbsNode::query()->whereKey($nodeId)->where('project_id', $project->id)->first();

        return $node ?? throw ValidationException::withMessages(['node_id' => __('projects_errors.wbs.node_not_in_project')]);
    }

    private function ownDependency(Project $project, string $dependencyId): ProjectWbsDependency
    {
        $dependency = ProjectWbsDependency::query()->whereKey($dependencyId)->where('project_id', $project->id)->first();

        return $dependency ?? throw ValidationException::withMessages(['dependency_id' => __('projects_errors.wbs.dependency_not_in_project')]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function nodeValues(array $attributes): array
    {
        $values = array_intersect_key($attributes, array_flip(self::NodeFields));

        if (($values['node_type'] ?? null) === WbsNodeType::Milestone->value) {
            $values['end_date'] = $values['start_date'] ?? $values['end_date'] ?? null;
            $values['planned_hours'] = $values['planned_hours'] ?? 0;
        }

        if ((($values['status'] ?? null) === 'done') && ! array_key_exists('percent_complete', $values)) {
            $values['percent_complete'] = 100;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertDates(array $values): void
    {
        $start = $values['start_date'] ?? null;
        $end = $values['end_date'] ?? null;

        if ($start !== null && $end !== null && (string) $end < (string) $start) {
            throw ValidationException::withMessages(['end_date' => __('projects_errors.schedule.end_before_start')]);
        }
    }

    /**
     * A parent must belong to the project, cannot be a milestone and cannot carry dependencies (those need a leaf).
     */
    private function assertCanHaveChildren(Project $project, string $parentId): void
    {
        $parent = ProjectWbsNode::query()->whereKey($parentId)->where('project_id', $project->id)->first();

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_node_id' => __('projects_errors.wbs.node_not_in_project')]);
        }

        if ($parent->node_type === WbsNodeType::Milestone->value) {
            throw ValidationException::withMessages(['parent_node_id' => __('projects_errors.wbs.milestone_cannot_have_children')]);
        }

        $hasChildren = ProjectWbsNode::query()->where('parent_node_id', $parent->id)->exists();
        $hasLinks = ProjectWbsDependency::query()
            ->where('project_id', $project->id)
            ->where(fn ($query) => $query->where('predecessor_node_id', $parent->id)->orWhere('successor_node_id', $parent->id))
            ->exists();

        if (! $hasChildren && $hasLinks) {
            throw new ConflictHttpException(__('projects_errors.wbs.parent_has_dependencies'));
        }
    }

    private function assertLeaf(Project $project, string $nodeId, string $field): void
    {
        $node = ProjectWbsNode::query()->whereKey($nodeId)->where('project_id', $project->id)->first();

        if ($node === null) {
            throw ValidationException::withMessages([$field => __('projects_errors.wbs.node_not_in_project')]);
        }

        if (ProjectWbsNode::query()->where('parent_node_id', $node->id)->exists()) {
            throw ValidationException::withMessages([$field => __('projects_errors.wbs.dependency_on_summary')]);
        }
    }

    private function move(Project $project, ProjectWbsNode $node, ?string $parentId, ?int $position): void
    {
        if ($parentId !== null) {
            if ($parentId === $node->id || in_array($parentId, $this->subtreeIds($this->projectNodes($project), $node->id), true)) {
                throw ValidationException::withMessages(['parent_node_id' => __('projects_errors.wbs.move_below_itself')]);
            }

            if ($parentId !== $node->parent_node_id) {
                $this->assertCanHaveChildren($project, $parentId);
            }
        }

        $siblings = ProjectWbsNode::query()->where('project_id', $project->id)->where('parent_node_id', $parentId)->whereKeyNot($node->id);
        $target = $position ?? ((int) (clone $siblings)->max('sort_order')) + 1;

        (clone $siblings)->where('sort_order', '>=', $target)->increment('sort_order');
        $node->update(['parent_node_id' => $parentId, 'sort_order' => $target]);
    }

    /**
     * @param  Collection<int, ProjectWbsNode>  $nodes
     * @return list<string> the node and all its descendants
     */
    private function subtreeIds(Collection $nodes, string $rootId): array
    {
        $children = [];

        foreach ($nodes as $node) {
            if ($node->parent_node_id !== null) {
                $children[$node->parent_node_id][] = $node->id;
            }
        }

        $ids = [];
        $stack = [$rootId];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (in_array($id, $ids, true)) {
                continue;
            }

            $ids[] = $id;

            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }

    /**
     * @param  Collection<int, ProjectWbsNode>  $nodes
     *
     * @throws ValidationException when following the parents of a node leads back to it
     */
    private function assertAcyclicTree(Collection $nodes): void
    {
        $parentOf = $nodes->pluck('parent_node_id', 'id')->all();

        foreach (array_keys($parentOf) as $id) {
            $seen = [$id => true];
            $cursor = $parentOf[$id] ?? null;

            while ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    throw ValidationException::withMessages(['parent_node_id' => __('projects_errors.wbs.move_below_itself')]);
                }

                $seen[$cursor] = true;
                $cursor = $parentOf[$cursor] ?? null;
            }
        }
    }
}
