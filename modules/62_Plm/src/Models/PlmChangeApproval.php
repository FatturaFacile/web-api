<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of the approval flow of a change order: approvers decide in sequence or by department.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $change_order_id
 * @property string $approver_id
 * @property int $sequence
 * @property string $status
 * @property string|null $department
 * @property string|null $actor_role
 * @property string|null $sign_off_hash
 * @property array<string, mixed>|null $metadata_json
 * @property Carbon|null $decided_at
 * @property string|null $comment
 */
final class PlmChangeApproval extends BaseEnterpriseModel
{
    public const string Pending = 'pending';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    protected $table = 'plm_change_approvals';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'decided_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PlmChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(PlmChangeOrder::class, 'change_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
