<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MfgProductionOrderOperation extends BaseEnterpriseModel
{
    protected $table = 'mfg_production_order_operations';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'planned_setup_minutes' => 'decimal:2',
            'actual_setup_minutes' => 'decimal:2',
            'planned_run_minutes' => 'decimal:2',
            'actual_run_minutes' => 'decimal:2',
            'completed_quantity' => 'decimal:4',
            'scrapped_quantity' => 'decimal:4',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MfgOperation::class, 'operation_id');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    public function machineCenter(): BelongsTo
    {
        return $this->belongsTo(MfgMachineCenter::class, 'machine_center_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(MfgShopFloorExecution::class, 'production_order_operation_id');
    }
}
