<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function plannedOrders(): HasMany
    {
        return $this->hasMany(MfgMrpPlannedOrder::class, 'mrp_run_id');
    }

    public function shortages(): HasMany
    {
        return $this->hasMany(MfgMrpShortage::class, 'mrp_run_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
