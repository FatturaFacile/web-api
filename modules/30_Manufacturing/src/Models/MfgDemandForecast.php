<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A demand forecast entered by the planner: quantity of an item expected on a date, an independent demand of MRP.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $mfg_item_id
 * @property Carbon $forecast_date
 * @property string $quantity
 * @property string|null $notes
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgDemandForecast extends BaseEnterpriseModel
{
    protected $table = 'mfg_demand_forecasts';

    protected function casts(): array
    {
        return [
            'forecast_date' => 'date',
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MfgItem, $this>
     */
    public function mfgItem(): BelongsTo
    {
        return $this->belongsTo(MfgItem::class, 'mfg_item_id');
    }
}
