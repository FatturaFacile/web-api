<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One recorded count of a line: every round is kept, `client_entry_id` makes an offline resend idempotent.
 */
final class InventoryCountEntry extends BaseEnterpriseModel
{
    protected $table = 'inventory_count_entries';

    protected function casts(): array
    {
        return ['counted_quantity' => 'decimal:4', 'counted_at' => 'datetime', 'round' => 'integer'];
    }
}
