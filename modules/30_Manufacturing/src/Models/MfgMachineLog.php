<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AssetManagement\Models\Asset;

final class MfgMachineLog extends BaseEnterpriseModel
{
    protected $table = 'mfg_machine_logs';

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'run_minutes' => 'decimal:2',
            'downtime_minutes' => 'decimal:2',
            'hourly_rate' => 'decimal:4',
            'machine_cost' => 'decimal:4',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(MfgShopFloorExecution::class, 'execution_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'machine_id');
    }
}
