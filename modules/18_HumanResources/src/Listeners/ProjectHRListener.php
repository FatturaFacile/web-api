<?php

declare(strict_types=1);

namespace Modules\HumanResources\Listeners;

use Modules\HumanResources\Models\Timesheet;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Events\ProjectCreatedEvent;

final readonly class ProjectHRListener
{
    public function handleProjectCreated(ProjectCreatedEvent $event): void
    {
        // Project created: ready for HR project team assignment and timesheet logging
    }

    public function handleProjectClosureReady(ProjectClosureReadyEvent $event): void
    {
        // Finalize any approved project timesheets into posted_to_project state
        Timesheet::where('tenant_id', $event->tenantId)
            ->where('project_id', $event->projectId)
            ->where('status', 'approved')
            ->update(['status' => 'posted_to_project']);
    }
}
