<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Audit log of a count session: freeze, unfreeze, assignment, recount, approval and rejection.
 */
final class InventoryCountEvent extends BaseEnterpriseModel
{
    protected $table = 'inventory_count_events';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
