<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectTaskAssignment extends BaseEnterpriseModel
{
    protected $table = 'project_task_assignments';

    protected function casts(): array
    {
        return [
            'allocated_hours' => 'decimal:2',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
