<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AssetManagement\Models\Asset;
use Modules\HumanResources\Models\Employee;

final class MfgShopFloorExecution extends BaseEnterpriseModel
{
    protected $table = 'mfg_shop_floor_executions';

    protected function casts(): array
    {
        return [
            'good_units' => 'decimal:4',
            'scrap_units' => 'decimal:4',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrder::class, 'production_order_id');
    }

    public function productionOrderOperation(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrderOperation::class, 'production_order_operation_id');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'machine_id');
    }

    public function laborLogs(): HasMany
    {
        return $this->hasMany(MfgLaborTimeLog::class, 'execution_id');
    }

    public function machineLogs(): HasMany
    {
        return $this->hasMany(MfgMachineLog::class, 'execution_id');
    }
}
