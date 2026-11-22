<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Infrastructure\Adapters;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;
use Modules\ResourcePlanning\Domain\Contracts\ProjectPort;
use Modules\ResourcePlanning\Domain\ValueObjects\ProjectRef;
use Modules\ResourcePlanning\Domain\ValueObjects\WbsRef;

/**
 * Reads projects and their work breakdown structure (Mod 078). A project accepts allocations while it is planned, approved
 * or active; drafts, projects on hold, completed, closed and cancelled projects do not.
 */
final class ProjectsAdapter implements ProjectPort
{
    private const array AllocatableStatuses = ['planned', 'approved', 'active'];

    public function project(string $projectId): ?ProjectRef
    {
        $project = Project::query()->find($projectId);

        return $project === null ? null : new ProjectRef((string) $project->id, (string) $project->name, (string) $project->status, in_array($project->status, self::AllocatableStatuses, true));
    }

    public function wbsNode(string $wbsNodeId): ?WbsRef
    {
        $task = ProjectTask::query()->find($wbsNodeId);

        return $task === null ? null : new WbsRef((string) $task->id, (string) $task->project_id, (string) $task->title);
    }

    public function projectNames(array $projectIds): array
    {
        return $projectIds === [] ? [] : Project::query()->whereIn('id', $projectIds)->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all();
    }

    public function wbsTitles(array $wbsNodeIds): array
    {
        return $wbsNodeIds === [] ? [] : ProjectTask::query()->whereIn('id', $wbsNodeIds)->pluck('title', 'id')->map(static fn ($title): string => (string) $title)->all();
    }
}
