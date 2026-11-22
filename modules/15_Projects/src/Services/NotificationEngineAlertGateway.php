<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Projects\Contracts\ProjectEvmAlertGatewayInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Notifications\ProjectEvmAlertNotification;
use Modules\Projects\Policies\ProjectPolicy;

/**
 * Notification & Alert Engine (Mod 008) adapter: a stored notification for the project manager and the managers of the
 * company, limited to the members of the tenant.
 */
final class NotificationEngineAlertGateway implements ProjectEvmAlertGatewayInterface
{
    public function notify(Project $project, array $breach): int
    {
        $users = User::query()->whereIn('id', $this->recipientIds($project))->get();

        if ($users->isEmpty()) {
            return 0;
        }

        Notification::send($users, new ProjectEvmAlertNotification('PROJECTS.EVM_ALERT_'.strtoupper($breach['metric']), [
            'project_id' => $project->id,
            'project_number' => $project->project_number,
            'project_name' => $project->name,
            'wbs_node_id' => $breach['wbs_node_id'],
            'wbs_label' => $breach['wbs_label'],
            'metric' => $breach['metric'],
            'value' => $breach['value'],
            'threshold' => $breach['threshold'],
            'severity' => $breach['severity'],
        ]));

        return $users->count();
    }

    /**
     * @return list<string>
     */
    private function recipientIds(Project $project): array
    {
        $relation = (new User)->tenants();
        $managers = DB::table($relation->getTable())
            ->where($relation->getRelatedPivotKeyName(), $project->tenant_id)
            ->whereIn('role', ProjectPolicy::ManagerRoles)
            ->pluck($relation->getForeignPivotKeyName())
            ->all();

        $ids = array_filter([...$managers, $project->project_manager_user_id]);
        $members = DB::table($relation->getTable())
            ->where($relation->getRelatedPivotKeyName(), $project->tenant_id)
            ->whereIn($relation->getForeignPivotKeyName(), array_values(array_unique(array_map('strval', $ids))))
            ->pluck($relation->getForeignPivotKeyName())
            ->all();

        return array_values(array_unique(array_map('strval', $members)));
    }
}
