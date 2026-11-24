<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The decision of one approver on one step of an approval request.
 *
 * @property string $id
 * @property string $request_id
 * @property int $step_position
 * @property string $step_name
 * @property ?string $decided_by
 * @property string $decision
 * @property ?string $comment
 * @property Carbon $decided_at
 */
final class DmsApprovalDecision extends BaseEnterpriseModel
{
    protected $table = 'dms_approval_decisions';

    protected function casts(): array
    {
        return ['step_position' => 'integer', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<DmsApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DmsApprovalRequest::class, 'request_id');
    }
}
