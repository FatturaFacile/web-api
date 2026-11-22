<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A run of the ABC classification: its parameters and class counts. The ranked items are InventoryAbcRunItem rows, the
 * historical snapshot of the run.
 *
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
final class InventoryAbcRun extends BaseEnterpriseModel
{
    protected $table = 'inventory_abc_runs';

    /**
     * @return HasMany<InventoryAbcRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryAbcRunItem::class, 'inventory_abc_run_id');
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'a_share' => 'decimal:2',
            'b_share' => 'decimal:2',
            'total_consumption_value' => 'decimal:2',
            'applied' => 'boolean',
        ];
    }
}
