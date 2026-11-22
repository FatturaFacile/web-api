<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Actions\CreateProjectAction;
use Modules\Projects\DTOs\CreateProjectDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTemplate;
use Modules\Projects\Models\ProjectWbsDependency;
use Modules\Projects\Models\ProjectWbsNode;
use Modules\Projects\Support\Money;
use Modules\Projects\Wbs\Enums\DependencyType;
use Modules\Projects\Wbs\Enums\WbsNodeType;

/**
 * Opens a project from a template: the project itself, the WBS hierarchy with the dates shifted relative to the project
 * start, the dependencies between the template nodes and the role each node needs.
 *
 * `default_wbs_structure` of the template is either a list of nodes or `{nodes: [...], dependencies: [...]}`. A node has
 * `title`, `node_type`, `ref` (a handle for dependencies), `start_offset_days` and `duration_days` (relative to the
 * project start; milestones have no duration), `planned_hours`, `planned_cost`, `planned_revenue`, `required_role` and
 * `children`. A dependency has `predecessor`, `successor` (refs), `type` (FS, SS, FF, SF) and `lag_days`.
 */
final readonly class ProjectFromTemplateService
{
    public function __construct(
        private CreateProjectAction $createProject,
        private ProjectWbsService $wbs,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated project data (see StoreProjectFromTemplateRequest)
     *
     * @throws ValidationException when the template structure is invalid or its dependencies form a cycle
     */
    public function instantiate(ProjectTemplate $template, array $data, string $actorUserId, string $tenantId): Project
    {
        return DB::transaction(function () use ($template, $data, $actorUserId, $tenantId): Project {
            ['nodes' => $nodes, 'dependencies' => $dependencies] = $this->structure($template);
            $start = CarbonImmutable::parse((string) $data['planned_start_date'])->startOfDay();
            $end = isset($data['planned_end_date']) ? CarbonImmutable::parse((string) $data['planned_end_date'])->startOfDay() : $this->templateEnd($nodes, $start);

            $project = $this->createProject->execute(new CreateProjectDTO(
                contactId: $data['contact_id'],
                projectNumber: $data['project_number'],
                name: $data['name'] ?? $template->name,
                projectType: $data['project_type'],
                billingMethod: $data['billing_method'],
                plannedStartDate: $start->toDateString(),
                plannedEndDate: $end->toDateString(),
                projectManagerUserId: $data['project_manager_user_id'],
                contractValue: Money::of($data['contract_value'] ?? 0),
                portfolioId: $data['portfolio_id'] ?? null,
                description: $data['description'] ?? $template->description,
                costCenterId: $data['cost_center_id'] ?? null,
                userId: $actorUserId,
                companyId: $tenantId,
                revenueRecognitionMethod: $data['revenue_recognition_method'] ?? null,
                currency: strtoupper($data['currency'] ?? 'EUR'),
                cupCode: $data['cup_code'] ?? null,
                cigCode: $data['cig_code'] ?? null,
                sdiReferenceBlock: $data['sdi_reference_block'] ?? 'contract',
            ), $tenantId);

            $refs = [];
            $this->createNodes($project, $nodes, null, $start, $refs);

            foreach ($dependencies as $dependency) {
                $predecessor = $refs[$dependency['predecessor'] ?? ''] ?? null;
                $successor = $refs[$dependency['successor'] ?? ''] ?? null;
                $type = DependencyType::tryFrom(strtoupper((string) ($dependency['type'] ?? 'FS')));

                if ($predecessor === null || $successor === null || $type === null) {
                    throw ValidationException::withMessages(['template_id' => __('projects_errors.wbs.template_invalid_dependency')]);
                }

                ProjectWbsDependency::create([
                    'tenant_id' => $project->tenant_id,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'predecessor_node_id' => $predecessor,
                    'successor_node_id' => $successor,
                    'dependency_type' => $type->value,
                    'lag_days' => (int) ($dependency['lag_days'] ?? 0),
                ]);
            }

            $this->wbs->refresh($project);

            return $project->refresh()->load(['phases', 'milestones']);
        });
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, dependencies: list<array<string, mixed>>}
     */
    private function structure(ProjectTemplate $template): array
    {
        $raw = $template->default_wbs_structure;
        $raw = is_array($raw) ? $raw : [];

        if (array_key_exists('nodes', $raw)) {
            return [
                'nodes' => array_values((array) $raw['nodes']),
                'dependencies' => array_values((array) ($raw['dependencies'] ?? [])),
            ];
        }

        return ['nodes' => array_values($raw), 'dependencies' => []];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function templateEnd(array $nodes, CarbonImmutable $start): CarbonImmutable
    {
        $end = $start;

        foreach ($nodes as $node) {
            $children = (array) ($node['children'] ?? []);

            if ($children !== []) {
                $candidate = $this->templateEnd(array_values($children), $start);
            } else {
                $nodeStart = $start->addDays((int) ($node['start_offset_days'] ?? 0));
                $isMilestone = WbsNodeType::fromInput((string) ($node['node_type'] ?? '')) === WbsNodeType::Milestone;
                $candidate = $isMilestone ? $nodeStart : $nodeStart->addDays(max(1, (int) ($node['duration_days'] ?? 1)) - 1);
            }

            if ($candidate->gt($end)) {
                $end = $candidate;
            }
        }

        return $end;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, string>  $refs  template ref => created node id
     */
    private function createNodes(Project $project, array $nodes, ?string $parentId, CarbonImmutable $start, array &$refs): void
    {
        foreach ($nodes as $index => $node) {
            $children = array_values((array) ($node['children'] ?? []));
            $type = WbsNodeType::fromInput((string) ($node['node_type'] ?? ''))
                ?? ($children !== [] ? WbsNodeType::Phase : WbsNodeType::WorkPackage);
            $values = [
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'parent_node_id' => $parentId,
                'wbs_code' => '',
                'title' => (string) ($node['title'] ?? ''),
                'node_type' => $type->value,
                'status' => 'todo',
                'required_role' => $node['required_role'] ?? null,
                'sort_order' => $index + 1,
            ];

            if ($values['title'] === '') {
                throw ValidationException::withMessages(['template_id' => __('projects_errors.wbs.template_invalid_node')]);
            }

            if ($children === []) {
                $nodeStart = $start->addDays((int) ($node['start_offset_days'] ?? 0));
                $values += [
                    'planned_hours' => Money::amount($type->isMilestone() ? 0 : ($node['planned_hours'] ?? 0)),
                    'planned_cost' => Money::amount($node['planned_cost'] ?? 0),
                    'planned_revenue' => Money::amount($node['planned_revenue'] ?? 0),
                    'start_date' => $nodeStart->toDateString(),
                    'end_date' => ($type->isMilestone() ? $nodeStart : $nodeStart->addDays(max(1, (int) ($node['duration_days'] ?? 1)) - 1))->toDateString(),
                ];
            }

            $created = ProjectWbsNode::create($values);

            if (isset($node['ref'])) {
                $refs[(string) $node['ref']] = $created->id;
            }

            if ($children !== []) {
                $this->createNodes($project, $children, $created->id, $start, $refs);
            }
        }
    }
}
