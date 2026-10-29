<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HumanResources\Models\Employee;

final class MfgLaborTimeLog extends BaseEnterpriseModel
{
    protected $table = 'mfg_labor_time_logs';

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'duration_minutes' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'labor_cost' => 'decimal:4',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(MfgShopFloorExecution::class, 'execution_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'operator_id');
    }
}
