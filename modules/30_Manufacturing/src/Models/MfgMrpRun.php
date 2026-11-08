<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An MRP run over a planning horizon, with the planned orders and shortages it produced.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_number
 * @property string $run_type
 * @property string $status
 * @property Carbon $horizon_start_date
 * @property Carbon $horizon_end_date
 * @property int $total_items_processed
 * @property int $total_planned_orders
 * @property int $total_shortages
 * @property string|null $executed_by
 * @property Carbon|null $executed_at
 * @property string|null $error_message
 * @property array<string, mixed>|null $parameters
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMrpRun extends BaseEnterpriseModel
{
    protected $table = 'mfg_mrp_runs';

    protected function casts(): array
    {
        return [
            'horizon_start_date' => 'date',
            'horizon_end_date' => 'date',
            'total_items_processed' => 'integer',
            'total_planned_orders' => 'integer',
            'total_shortages' => 'integer',
            'executed_at' => 'datetime',
            'parameters' => 'array',
        ];
    }

    /**
     * @return HasMany<MfgMrpPlannedOrder, $this>
     */
    public function plannedOrders(): HasMany
    {
        return $this->hasMany(MfgMrpPlannedOrder::class, 'mrp_run_id');
    }

    /**
     * @return HasMany<MfgMrpShortage, $this>
     */
    public function shortages(): HasMany
    {
        return $this->hasMany(MfgMrpShortage::class, 'mrp_run_id');
    }

    /**
     * Whether the run finished.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
