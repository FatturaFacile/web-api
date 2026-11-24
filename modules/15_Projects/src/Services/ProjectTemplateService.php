<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTemplate;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Support\Money;
use Modules\Projects\Wbs\Enums\DependencyType;
use Modules\Projects\Wbs\Enums\WbsNodeType;

/**
 * Reusable project templates: the WBS hierarchy of a project is saved with its dates relative to the project start, its
 * dependencies and the role each node needs, so that ProjectFromTemplateService can open new projects from it.
 *
 * The stored structure is `{nodes: [...], dependencies: [...]}` (see ProjectFromTemplateService).
 */
final readonly class ProjectTemplateService
{
    /**
     * Saves the current WBS of a project as a template.
     *
     * @throws ValidationException when the project has no WBS to save
     */
    public function captureFromProject(Project $project, string $code, string $name, ?string $description, string $tenantId): ProjectTemplate
    {
        $nodes = ProjectWbsNode::query()->where('project_id', $project->id)->orderBy('sort_order')->get();

        if ($nodes->isEmpty()) {
            throw ValidationException::withMessages(['source_project_id' => __('projects_errors.wbs.template_needs_nodes')]);
        }

        /** @var Collection<string, Collection<int, ProjectWbsNode>> $byParent */
        $byParent = $nodes->groupBy(fn (ProjectWbsNode $node): string => $node->parent_node_id ?? '');
        $earliest = $nodes->filter(fn (ProjectWbsNode $node): bool => $node->start_date !== null)->min(fn (ProjectWbsNode $node) => $node->start_date?->toDateString());
        $origin = CarbonImmutable::parse($project->planned_start_date?->toDateString() ?? (is_string($earliest) ? $earliest : now()->toDateString()))->startOfDay();

        $structure = [
            'nodes' => $this->tree($byParent, '', $origin),
            'dependencies' => ProjectWbsDependency::query()->where('project_id', $project->id)->get()
                ->map(fn (ProjectWbsDependency $dependency): array => [
                    'predecessor' => $dependency->predecessor_node_id,
                    'successor' => $dependency->successor_node_id,
                    'type' => $dependency->dependency_type,
                    'lag_days' => (int) $dependency->lag_days,
                ])->values()->all(),
        ];

        return $this->persist($code, $name, $description, $structure, $tenantId);
    }

    /**
     * Saves a hand-written structure as a template.
     *
     * @param  array{nodes: list<array<string, mixed>>, dependencies?: list<array<string, mixed>>}  $structure
     *
     * @throws ValidationException when a node has no title, a type is unknown or a dependency points to an unknown node
     */
    public function create(string $code, string $name, ?string $description, array $structure, string $tenantId): ProjectTemplate
    {
        $refs = [];
        $this->assertNodes($structure['nodes'], $refs);

        foreach ($structure['dependencies'] ?? [] as $dependency) {
            $type = DependencyType::tryFrom(strtoupper((string) ($dependency['type'] ?? 'FS')));

            if (! isset($refs[(string) ($dependency['predecessor'] ?? '')], $refs[(string) ($dependency['successor'] ?? '')]) || $type === null) {
                throw ValidationException::withMessages(['structure' => __('projects_errors.wbs.template_invalid_dependency')]);
            }
        }

        return $this->persist($code, $name, $description, ['nodes' => $structure['nodes'], 'dependencies' => $structure['dependencies'] ?? []], $tenantId);
    }

    /**
     * @param  array<string, mixed>  $structure
     */
    private function persist(string $code, string $name, ?string $description, array $structure, string $tenantId): ProjectTemplate
    {
        return DB::transaction(fn (): ProjectTemplate => ProjectTemplate::create([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'default_wbs_structure' => $structure,
            'is_active' => true,
        ]));
    }

    /**
     * @param  Collection<string, Collection<int, ProjectWbsNode>>  $byParent
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $byParent, string $parentKey, CarbonImmutable $origin): array
    {
        $out = [];

        foreach ($byParent->get($parentKey, collect()) as $node) {
            $item = [
                'ref' => $node->id,
                'title' => $node->title,
                'node_type' => $node->node_type,
                'required_role' => $node->required_role,
            ];
            $children = $this->tree($byParent, $node->id, $origin);

            if ($children !== []) {
                $item['children'] = $children;
            } else {
                $start = $node->start_date === null ? $origin : CarbonImmutable::parse($node->start_date->toDateString())->startOfDay();
                $end = $node->end_date === null ? $start : CarbonImmutable::parse($node->end_date->toDateString())->startOfDay();
                $isMilestone = WbsNodeType::fromInput($node->node_type)?->isMilestone() === true;
                $item['start_offset_days'] = max(0, (int) $origin->diffInDays($start, false));
                $item['duration_days'] = $isMilestone ? 0 : max(1, (int) $start->diffInDays($end, false) + 1);
                $item['planned_hours'] = Money::amount($isMilestone ? 0 : $node->planned_hours);
                $item['planned_cost'] = Money::amount($node->planned_cost);
                $item['planned_revenue'] = Money::amount($node->planned_revenue);
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, true>  $refs
     */
    private function assertNodes(array $nodes, array &$refs): void
    {
        foreach ($nodes as $node) {
            if (trim((string) ($node['title'] ?? '')) === '') {
                throw ValidationException::withMessages(['structure' => __('projects_errors.wbs.template_invalid_node')]);
            }

            if (isset($node['node_type']) && WbsNodeType::fromInput((string) $node['node_type']) === null) {
                throw ValidationException::withMessages(['structure' => __('projects_errors.wbs.template_invalid_node')]);
            }

            if (isset($node['ref'])) {
                $refs[(string) $node['ref']] = true;
            }

            $this->assertNodes(array_values((array) ($node['children'] ?? [])), $refs);
        }
    }
}
