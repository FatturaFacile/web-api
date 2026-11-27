<?php

declare(strict_types=1);

namespace Modules\Distribution\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\Warehouse;

/**
 * A comparison of the stock the system holds at a 3PL warehouse with the quantities the provider reports.
 *
 * @property string $warehouse_id
 * @property string|null $external_reference
 * @property string $status
 * @property Carbon|null $reconciled_at
 * @property list<array<string, mixed>> $lines
 */
final class ThreePlReconciliation extends BaseEnterpriseModel
{
    protected $table = 'distribution_threepl_reconciliations';

    protected function casts(): array
    {
        return [
            'reconciled_at' => 'datetime',
            'lines' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}
