<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Audit row of a manual ABC class override (`new_class` null = the override was cleared).
 *
 * @property Carbon $set_at
 */
final class InventoryAbcOverride extends BaseEnterpriseModel
{
    protected $table = 'inventory_abc_overrides';

    protected function casts(): array
    {
        return ['set_at' => 'datetime'];
    }
}
