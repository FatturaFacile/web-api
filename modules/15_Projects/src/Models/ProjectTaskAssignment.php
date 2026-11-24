<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person assigned to a task with the hours planned for them; the hours are spread over the working days of the task
 * to compute the weekly load against the capacity of the person.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_task_id
 * @property string $user_id
 * @property string|null $role_description
 * @property string $allocated_hours
 */
final class ProjectTaskAssignment extends BaseEnterpriseModel
{
    protected $table = 'project_task_assignments';

    protected function casts(): array
    {
        return [
            'allocated_hours' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ProjectTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
