<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MfgOperation extends BaseEnterpriseModel
{
    protected $table = 'mfg_operations';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'setup_time_minutes' => 'decimal:2',
            'run_time_minutes_per_unit' => 'decimal:2',
            'labor_time_minutes_per_unit' => 'decimal:2',
            'queue_time_minutes' => 'decimal:2',
            'wait_time_minutes' => 'decimal:2',
            'teardown_time_minutes' => 'decimal:2',
            'standard_labor_rate' => 'decimal:4',
            'standard_machine_rate' => 'decimal:4',
        ];
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    public function machineCenter(): BelongsTo
    {
        return $this->belongsTo(MfgMachineCenter::class, 'machine_center_id');
    }

    public function calculateTotalStandardTimeMinutes(float $units): float
    {
        $setup = (float) $this->setup_time_minutes;
        $run = (float) $this->run_time_minutes_per_unit * $units;
        $teardown = (float) $this->teardown_time_minutes;

        return round($setup + $run + $teardown, 2);
    }
}
