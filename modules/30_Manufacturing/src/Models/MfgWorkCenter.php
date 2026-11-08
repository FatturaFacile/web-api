<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Support\Money;

/**
 * A work centre: daily capacity (overridden by its weekly calendar and dated exceptions), efficiency and availability,
 * and the labour and overhead rates per hour that cost the operations run on it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $work_center_code
 * @property string $name
 * @property string|null $description
 * @property string|null $department_code
 * @property string $capacity_hours_per_day
 * @property string $efficiency_rate
 * @property string $availability_rate
 * @property string $hourly_cost_rate
 * @property string $hourly_overhead_rate
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MfgWorkCenterShift> $shifts
 */
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

    /**
     * @return HasMany<MfgMachineCenter, $this>
     */
    public function machineCenters(): HasMany
    {
        return $this->hasMany(MfgMachineCenter::class, 'work_center_id');
    }

    /**
     * @return HasMany<MfgOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(MfgOperation::class, 'work_center_id');
    }

    /**
     * Dated capacity exceptions (holidays, extra shifts).
     *
     * @return HasMany<MfgWorkCenterCapacity, $this>
     */
    public function capacities(): HasMany
    {
        return $this->hasMany(MfgWorkCenterCapacity::class, 'work_center_id');
    }

    /**
     * Weekly calendar: available hours per weekday.
     *
     * @return HasMany<MfgWorkCenterShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(MfgWorkCenterShift::class, 'work_center_id')->orderBy('weekday');
    }

    /**
     * Effective hours of a day with the given gross hours: gross hours times efficiency and availability.
     */
    public function effectiveHours(string $grossHours): string
    {
        return Money::mul(Money::mul($grossHours, Money::div($this->efficiency_rate, '100')), Money::div($this->availability_rate, '100'));
    }
}
