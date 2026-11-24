<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $warehouse_id
 * @property string|null $cost_center_id
 * @property string|null $working_calendar_id
 * @property string|null $parent_work_center_id
 * @property int $parallel_capacity_units
 * @property string $oee_target_percent
 * @property string|null $availability_target_percent
 * @property string|null $performance_target_percent
 * @property string|null $quality_target_percent
 * @property string $hourly_setup_cost_rate
 * @property string $hourly_machine_run_cost_rate
 * @property string $currency_code
 * @property int $default_queue_wait_minutes
 * @property int $default_move_time_minutes
 * @property list<string>|null $alternate_work_center_ids
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
            'parallel_capacity_units' => 'integer',
            'oee_target_percent' => 'decimal:2',
            'availability_target_percent' => 'decimal:2',
            'performance_target_percent' => 'decimal:2',
            'quality_target_percent' => 'decimal:2',
            'hourly_setup_cost_rate' => 'decimal:4',
            'hourly_machine_run_cost_rate' => 'decimal:4',
            'default_queue_wait_minutes' => 'integer',
            'default_move_time_minutes' => 'integer',
            'alternate_work_center_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_work_center_id');
    }

    /**
     * @return HasMany<MfgWorkCenter, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_work_center_id');
    }

    /**
     * @return BelongsTo<MfgWorkingCalendar, $this>
     */
    public function workingCalendar(): BelongsTo
    {
        return $this->belongsTo(MfgWorkingCalendar::class, 'working_calendar_id');
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
