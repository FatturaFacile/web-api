<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Business audit trail of a project: who did what (approvals, closures, billing) with the state before and after.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $actor_user_id
 * @property string $event_type
 * @property string $description
 * @property array<string, mixed> $before_state
 * @property array<string, mixed> $after_state
 */
final class ProjectAuditEntry extends BaseEnterpriseModel
{
    protected $table = 'project_audit_entries';

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
