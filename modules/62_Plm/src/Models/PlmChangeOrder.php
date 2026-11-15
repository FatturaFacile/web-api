<?php

declare(strict_types=1);

namespace Modules\Plm\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An engineering change order: the reason for a change, the items and bills of material it affects and the approval
 * flow it goes through before it is implemented.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string $reason
 * @property string $change_type
 * @property string $priority
 * @property string $status
 * @property string|null $requested_by
 * @property Carbon|null $effective_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $implemented_at
 * @property string|null $rejection_reason
 * @property string|null $notes
 */
final class PlmChangeOrder extends BaseEnterpriseModel
{
    public const string Draft = 'draft';

    public const string InReview = 'in_review';

    public const string Approved = 'approved';

    public const string Rejected = 'rejected';

    public const string Implemented = 'implemented';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Draft, self::InReview, self::Approved, self::Rejected, self::Implemented, self::Cancelled];

    /** @var list<string> */
    public const array ChangeTypes = ['design', 'material', 'process', 'documentation'];

    /** @var list<string> */
    public const array Priorities = ['low', 'normal', 'high', 'critical'];

    protected $table = 'plm_change_orders';

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'implemented_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PlmChangeApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(PlmChangeApproval::class, 'change_order_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<PlmAffectedItem, $this>
     */
    public function affectedItems(): HasMany
    {
        return $this->hasMany(PlmAffectedItem::class, 'change_order_id');
    }

    /**
     * @return HasMany<PlmAffectedBom, $this>
     */
    public function affectedBoms(): HasMany
    {
        return $this->hasMany(PlmAffectedBom::class, 'change_order_id');
    }
}
