<?php

declare(strict_types=1);

namespace Modules\Workflow\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A decision taken on a step by a person, on behalf of a colleague (delegation), or by the engine itself.
 *
 * @property string $id
 * @property string $workflow_instance_step_id
 * @property string $user_id
 * @property string $effective_user_id
 * @property string $decision
 * @property ?string $comment
 * @property ?Carbon $decided_at
 */
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

    /**
     * @return BelongsTo<WorkflowInstanceStep, $this>
     */
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
