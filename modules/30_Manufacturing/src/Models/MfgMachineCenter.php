<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\AssetManagement\Models\FixedAsset;

/**
 * A machine of a work centre, optionally linked to the fixed asset that represents it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $machine_code
 * @property string $name
 * @property string $work_center_id
 * @property string|null $asset_id
 * @property string $hourly_rate
 * @property string $power_kw
 * @property string $status
 * @property Carbon|null $last_serviced_at
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $maintenance_schedule_id
 * @property bool $is_industria_40_enabled
 * @property bool $is_transizione_50_enabled
 * @property string|null $telemetry_device_id
 * @property string|null $telemetry_endpoint_key
 * @property Carbon|null $telemetry_last_seen_at
 * @property string|null $telemetry_status
 * @property string|null $ideal_cycle_time_seconds_per_unit
 * @property int $parallel_capacity_units
 * @property string|null $efficiency_factor_percent
 * @property string|null $hourly_machine_run_cost_rate_override
 * @property string|null $current_state RUNNING|SETUP|IDLE|FAULT|MAINTENANCE reported by the edge gateway
 * @property Carbon|null $state_since
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMachineCenter extends BaseEnterpriseModel
{
    protected $table = 'mfg_machine_centers';

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'power_kw' => 'decimal:2',
            'last_serviced_at' => 'datetime',
            'is_industria_40_enabled' => 'boolean',
            'is_transizione_50_enabled' => 'boolean',
            'telemetry_last_seen_at' => 'datetime',
            'ideal_cycle_time_seconds_per_unit' => 'decimal:4',
            'parallel_capacity_units' => 'integer',
            'efficiency_factor_percent' => 'decimal:2',
            'hourly_machine_run_cost_rate_override' => 'decimal:4',
            'state_since' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    /**
     * Whether the machine can be scheduled.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }
}
