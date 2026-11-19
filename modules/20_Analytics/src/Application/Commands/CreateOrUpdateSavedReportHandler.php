<?php

declare(strict_types=1);

namespace Modules\Analytics\Application\Commands;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Pivot\DesignedReportPlan;

/**
 * Validates the whole definition against the permissions of the author (a field they cannot see cannot be saved), checks
 * the visibility choice and stores the report. Changing an existing report needs the right to manage it. `is_shared` is
 * kept equal to "visible to the whole company" for the older code paths.
 */
final readonly class CreateOrUpdateSavedReportHandler
{
    public function __construct(private DesignedReportPlan $plans) {}

    public function handle(CreateOrUpdateSavedReportCommand $command): DesignedReport
    {
        $plan = $this->plans->build($command->definition, $command->user, 'spec.');

        if (! in_array($command->visibility, DesignedReport::Visibilities, true)) {
            throw ValidationException::withMessages(['visibility' => ['Unknown visibility.']]);
        }

        if ($command->visibility === DesignedReport::VisibilityRoleShared && $command->sharedRoles === []) {
            throw ValidationException::withMessages(['shared_role_ids' => ['Choose at least one role.']]);
        }

        $attributes = [
            'name' => $command->name,
            'description' => $command->description,
            'dataset' => $plan['spec']->dataset,
            'spec' => $plan['spec']->toArray(),
            'pivot' => $command->definition['pivot'] ?? [],
            'calculated_fields' => $plan['calculated'],
            'conditional_formatting' => $plan['pivot']->formatting ?? [],
            'chart_config' => $plan['pivot']->chart ?? [],
            'visibility' => $command->visibility,
            'shared_role_ids' => $command->visibility === DesignedReport::VisibilityRoleShared ? array_values(array_unique($command->sharedRoles)) : [],
            'is_shared' => $command->visibility === DesignedReport::VisibilityTenantPublic,
            'shared_with' => array_values(array_unique($command->sharedWith)),
        ];

        if ($command->id === null) {
            return DesignedReport::create([...$attributes, 'user_id' => $command->user->id]);
        }

        $report = DesignedReport::query()->visibleTo($command->user)->findOrFail($command->id);
        Gate::forUser($command->user)->authorize('manage', $report);
        $report->update($attributes);

        return $report;
    }
}
