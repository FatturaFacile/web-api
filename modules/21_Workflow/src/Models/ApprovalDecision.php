<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApprovalDecision extends BaseEnterpriseModel
{
    protected $table = 'approval_decisions';

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'signature_payload' => 'array',
        ];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstanceStep::class, 'workflow_instance_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function effectiveUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'effective_user_id');
    }

    public function approvalToken(): BelongsTo
    {
        return $this->belongsTo(ApprovalToken::class, 'approval_token_id');
    }
}
