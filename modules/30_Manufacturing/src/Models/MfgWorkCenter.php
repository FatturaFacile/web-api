<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MfgWorkCenter extends BaseEnterpriseModel
{
    protected $table = 'mfg_work_centers';

    protected function casts(): array
    {
        return [
            'capacity_hours_per_day' => 'decimal:2',
            'efficiency_rate' => 'decimal:2',
            'availability_rate' => 'decimal:2',
            'hourly_cost_rate' => 'decimal:4',
            'hourly_overhead_rate' => 'decimal:4',
        ];
    }

    public function machineCenters(): HasMany
    {
        return $this->hasMany(MfgMachineCenter::class, 'work_center_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(MfgOperation::class, 'work_center_id');
    }

    public function capacities(): HasMany
    {
        return $this->hasMany(MfgWorkCenterCapacity::class, 'work_center_id');
    }

    public function calculateDailyEffectiveCapacityHours(): float
    {
        $base = (float) $this->capacity_hours_per_day;
        $eff = (float) $this->efficiency_rate / 100.0;
        $avail = (float) $this->availability_rate / 100.0;

        return round($base * $eff * $avail, 2);
    }
}
