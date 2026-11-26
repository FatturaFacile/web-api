<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_instance_step_id
 * @property string $action_type
 * @property string $authorized_user_id
 * @property Carbon $expires_at
 * @property ?Carbon $used_at
 * @property bool $requires_reauth
 * @property ?string $amount
 * @property string $channel
 */
final class ApprovalToken extends BaseEnterpriseModel
{
    protected $table = 'approval_tokens';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'requires_reauth' => 'boolean',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<WorkflowInstanceStep, $this>
     */
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
