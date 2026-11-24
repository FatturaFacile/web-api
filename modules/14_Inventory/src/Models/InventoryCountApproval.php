<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One approval level of the differences of a count: level 1 is the warehouse manager, level 2 the finance approver.
 *
 * @property Carbon|null $decided_at
 */
final class InventoryCountApproval extends BaseEnterpriseModel
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    protected $table = 'inventory_count_approvals';

    protected function casts(): array
    {
        return ['level' => 'integer', 'decided_at' => 'datetime'];
    }
}
