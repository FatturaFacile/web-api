<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApprovalToken extends BaseEnterpriseModel
{
    protected $table = 'approval_tokens';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceStep::class, 'workflow_instance_step_id');
    }

    public function authorizedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_user_id');
    }

    public function isValid(): bool
    {
        return $this->used_at === null && now()->isBefore($this->expires_at);
    }
}
