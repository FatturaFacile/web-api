<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Events\ProjectPausedEvent;
use Modules\Projects\Events\ProjectResumedEvent;
use Modules\Projects\Events\ProjectStartedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectStatusHistory;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Status lifecycle of a project and changes of its master data, each under a lock of the project row.
 *
 *  draft / planned / approved -> active (start) -> on_hold (pause) -> active (resume) -> completed (complete)
 *
 * Closing is handled by ProjectClosingService. The contract value can be edited only before the project starts
 * (afterwards change orders move it) and the recognition method only while no cost is booked, because costs already
 * booked were capitalised (or not) under the previous method.
 */
final readonly class ProjectLifecycleService
{
    /** Allowed source statuses of each transition. */
    private const array Transitions = [
        'start' => [['draft', 'planned', 'approved'], 'active'],
        'pause' => [['active'], 'on_hold'],
        'resume' => [['on_hold'], 'active'],
        'complete' => [['active', 'on_hold'], 'completed'],
    ];

    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @param  'start'|'pause'|'resume'|'complete'  $transition
     *
     * @throws ConflictHttpException when the project is not in a status that allows the transition
     */
    public function transition(Project $project, string $transition, string $actorUserId, ?string $reason = null): Project
    {
        return DB::transaction(function () use ($project, $transition, $actorUserId, $reason): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            [$from, $to] = self::Transitions[$transition];

            if (! in_array($locked->status, $from, true)) {
                throw new ConflictHttpException("Project is {$locked->status}: it cannot {$transition}.");
            }

            $previous = $locked->status;
            $updates = ['status' => $to];

            if ($transition === 'start' && $locked->actual_start_date === null) {
                $updates['actual_start_date'] = now()->toDateString();
            }

            if ($transition === 'complete') {
                $updates['actual_end_date'] = now()->toDateString();
                $updates['progress_percentage'] = '100.00';
            }

            $locked->update($updates);

            ProjectStatusHistory::create([
                'tenant_id' => $locked->tenant_id,
                'company_id' => $locked->company_id,
                'project_id' => $locked->id,
                'from_status' => $previous,
                'to_status' => $to,
                'change_reason' => $reason,
                'changed_by' => $actorUserId,
            ]);

            $this->announce($locked, $transition, $actorUserId, $reason ?? '');
            $this->audit->record($locked, $actorUserId, "project_{$transition}", "Project {$transition}: {$previous} -> {$to}".($reason ? " ({$reason})" : ''), ['status' => $previous], ['status' => $to]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated master data
     *
     * @throws ValidationException when the contract value or the recognition method can no longer change
     * @throws ConflictHttpException when the project is closed
     */
    public function update(Project $project, array $attributes, string $actorUserId): Project
    {
        return DB::transaction(function () use ($project, $attributes, $actorUserId): Project {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new ConflictHttpException("Project is {$locked->status}: it can no longer change.");
            }

            if (array_key_exists('contract_value', $attributes) && Money::compare($attributes['contract_value'], $locked->contract_value) !== 0 && ! in_array($locked->status, ['draft', 'planned', 'approved'], true)) {
                throw ValidationException::withMessages(['contract_value' => 'The contract value of a started project changes only through change orders.']);
            }

            if (array_key_exists('revenue_recognition_method', $attributes) && $attributes['revenue_recognition_method'] !== $locked->revenue_recognition_method
                && (ProjectCostEntry::query()->where('project_id', $locked->id)->exists() || ProjectRevenueRecognition::query()->where('project_id', $locked->id)->exists())) {
                throw ValidationException::withMessages(['revenue_recognition_method' => 'The recognition method cannot change once costs are booked on the project.']);
            }

            if (isset($attributes['contract_value'])) {
                $attributes['contract_value'] = Money::amount($attributes['contract_value']);
            }

            $before = $locked->only(array_keys($attributes));
            $locked->update($attributes);
            $this->audit->record($locked, $actorUserId, 'project_updated', 'Project master data updated', $before, $locked->only(array_keys($attributes)));

            return $locked;
        });
    }

    /**
     * Deletes a draft project without bookings.
     *
     * @throws ConflictHttpException when time or costs are booked on it
     */
    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if (ProjectCostEntry::query()->where('project_id', $locked->id)->exists() || ProjectTimesheet::query()->where('project_id', $locked->id)->exists()) {
                throw new ConflictHttpException('Time or costs are booked on the project: it cannot be deleted.');
            }

            $locked->delete();
        });
    }

    private function announce(Project $project, string $transition, string $actorUserId, string $reason): void
    {
        $companyId = (string) ($project->company_id ?? $project->tenant_id);
        $tenantId = (string) $project->tenant_id;

        $event = match ($transition) {
            'start' => new ProjectStartedEvent(projectId: $project->id, projectNumber: $project->project_number, startDate: now()->toDateString(), tenantId: $tenantId, companyId: $companyId),
            'pause' => new ProjectPausedEvent(projectId: $project->id, projectNumber: $project->project_number, reason: $reason !== '' ? $reason : 'Paused', pausedBy: $actorUserId, tenantId: $tenantId, companyId: $companyId),
            'resume' => new ProjectResumedEvent(projectId: $project->id, projectNumber: $project->project_number, resumedBy: $actorUserId, tenantId: $tenantId, companyId: $companyId),
            default => null,
        };

        if ($event !== null) {
            $this->dispatcher->publishAndDispatch($event);
        }
    }
}
