<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The demand a planned order covers: sales order line, forecast, safety stock or the parent planned order.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $planned_order_id
 * @property string $demand_type
 * @property string|null $demand_reference_id
 * @property string $pegged_quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMrpPegging extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_peggings';

    protected function casts(): array
    {
        return [
            'pegged_quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MfgMrpPlannedOrder, $this>
     */
    public function plannedOrder(): BelongsTo
    {
        return $this->belongsTo(MfgMrpPlannedOrder::class, 'planned_order_id');
    }
}
