<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectStatusHistory;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Moves a project to a target status (`PATCH /projects/{id}/status`): the transitions the lifecycle service owns (start,
 * pause, resume, complete) are delegated to it; planning and cancelling are handled here.
 *
 * Accepts the snake_case status as well as the contract names Planning, Active, OnHold, Completed and Cancelled.
 */
final readonly class ProjectStatusService
{
    public const array Targets = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];

    public function __construct(
        private ProjectLifecycleService $lifecycle,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @throws ValidationException when the target is not a status the endpoint can set
     * @throws ConflictHttpException when the project cannot move to the target from its current status
     */
    public function change(Project $project, string $target, string $actorUserId, ?string $reason = null): Project
    {
        $status = self::normalise($target);

        if ($status === null) {
            throw ValidationException::withMessages(['status' => __('projects_errors.project.status_not_settable', ['status' => $target])]);
        }

        if ($project->status === $status) {
            return $project;
        }

        return match ($status) {
            'active' => $this->lifecycle->transition($project, $project->status === 'on_hold' ? 'resume' : 'start', $actorUserId, $reason),
            'on_hold' => $this->lifecycle->transition($project, 'pause', $actorUserId, $reason),
            'completed' => $this->lifecycle->transition($project, 'complete', $actorUserId, $reason),
            'planned' => $this->direct($project, ['draft'], 'planned', $actorUserId, $reason),
            default => $this->direct($project, ['draft', 'planned', 'approved', 'active', 'on_hold'], 'cancelled', $actorUserId, $reason),
        };
    }

    public static function normalise(string $target): ?string
    {
        $key = strtolower(str_replace(['_', '-', ' '], '', $target));

        return match ($key) {
            'planning', 'planned' => 'planned',
            'active' => 'active',
            'onhold' => 'on_hold',
            'completed' => 'completed',
            'cancelled', 'canceled' => 'cancelled',
            default => null,
        };
    }

    /**
     * @param  list<string>  $from
     */
    private function direct(Project $project, array $from, string $to, string $actorUserId, ?string $reason): Project
    {
        return DB::transaction(function () use ($project, $from, $to, $actorUserId, $reason): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new ConflictHttpException(__('projects_errors.project.cannot_change_status', ['status' => $locked->status, 'target' => $to]));
            }

            $previous = $locked->status;
            $locked->update(['status' => $to]);

            ProjectStatusHistory::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'project_id' => $locked->id,
                'from_status' => $previous,
                'to_status' => $to,
                'change_reason' => $reason,
                'changed_by' => $actorUserId,
            ]);

            $this->audit->record($locked, $actorUserId, "project_{$to}", "Project status {$previous} -> {$to}".($reason ? " ({$reason})" : ''), ['status' => $previous], ['status' => $to]);

            return $locked;
        });
    }
}
