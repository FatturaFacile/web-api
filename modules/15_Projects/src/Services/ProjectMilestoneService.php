<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Events\ProjectMilestoneCompletedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectPhase;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Milestones of a project. A completed milestone with a billing amount (or percentage of the contract value) becomes
 * billable; a billed milestone can no longer change, since its amount is on an invoice.
 */
final readonly class ProjectMilestoneService
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(Project $project, array $attributes): ProjectMilestone
    {
        $this->assertOpen($project);
        $this->assertPhase($project, $attributes);

        return ProjectMilestone::create([
            ...$this->amounts($attributes),
            'tenant_id' => $project->tenant_id,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ProjectMilestone $milestone, array $attributes): ProjectMilestone
    {
        return DB::transaction(function () use ($milestone, $attributes): ProjectMilestone {
            $locked = ProjectMilestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail();
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->assertOpen($project);
            $this->assertPhase($project, $attributes);

            if ($locked->is_billed) {
                throw new ConflictHttpException(__('projects_errors.milestone.billed_locked'));
            }

            $locked->update($this->amounts($attributes));

            return $locked;
        });
    }

    /**
     * Marks the milestone as reached; it becomes billable when it carries an amount.
     */
    public function complete(ProjectMilestone $milestone, string $actorUserId): ProjectMilestone
    {
        return DB::transaction(function () use ($milestone, $actorUserId): ProjectMilestone {
            $locked = ProjectMilestone::query()->whereKey($milestone->id)->lockForUpdate()->firstOrFail();
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->assertOpen($project);

            if ($locked->is_completed) {
                throw new ConflictHttpException(__('projects_errors.milestone.already_completed'));
            }

            $locked->update(['is_completed' => true, 'completed_at' => now()]);

            $this->dispatcher->publishAndDispatch(new ProjectMilestoneCompletedEvent(
                milestoneId: (string) $locked->id,
                projectId: (string) $project->id,
                milestoneName: $locked->name,
                billingAmount: (float) $locked->billing_amount,
                completedAtDate: now()->toDateString(),
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            $this->audit->record($project, $actorUserId, 'milestone_completed', "Milestone {$locked->name} completed", ['is_completed' => false], ['is_completed' => true], $locked);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function amounts(array $attributes): array
    {
        foreach (['billing_amount', 'billing_percentage'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = Money::amount($attributes[$field]);
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertPhase(Project $project, array $attributes): void
    {
        if (! empty($attributes['project_phase_id']) && ! ProjectPhase::query()->whereKey($attributes['project_phase_id'])->where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['project_phase_id' => __('projects_errors.phase.not_in_project')]);
        }
    }

    private function assertOpen(Project $project): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException(__('projects_errors.milestone.project_closed', ['number' => $project->project_number, 'status' => $project->status]));
        }
    }
}
