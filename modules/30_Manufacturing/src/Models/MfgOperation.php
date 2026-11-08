<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Support\Money;

/**
 * An operation of a routing: work centre, setup time, run time and labour time per unit, optional subcontracting.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $routing_id
 * @property int $sequence
 * @property string $operation_code
 * @property string $name
 * @property string|null $description
 * @property string $work_center_id
 * @property string|null $machine_center_id
 * @property string $setup_time_minutes
 * @property string $run_time_minutes_per_unit
 * @property string $labor_time_minutes_per_unit
 * @property string $queue_time_minutes
 * @property string $wait_time_minutes
 * @property string $teardown_time_minutes
 * @property string $standard_labor_rate
 * @property string $standard_machine_rate
 * @property bool $is_subcontracted
 * @property string|null $subcontractor_contact_id
 * @property string $subcontract_unit_cost
 * @property string|null $quality_plan_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MfgWorkCenter|null $workCenter
 */
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
            'is_subcontracted' => 'boolean',
            'subcontract_unit_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<MfgRouting, $this>
     */
    public function routing(): BelongsTo
    {
        return $this->belongsTo(MfgRouting::class, 'routing_id');
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }

    /**
     * @return BelongsTo<MfgMachineCenter, $this>
     */
    public function machineCenter(): BelongsTo
    {
        return $this->belongsTo(MfgMachineCenter::class, 'machine_center_id');
    }

    /**
     * Machine minutes for a quantity: setup plus run time per unit.
     */
    public function machineMinutesFor(string $quantity): string
    {
        return Money::add($this->setup_time_minutes, Money::mul($this->run_time_minutes_per_unit, $quantity));
    }

    /**
     * Labour minutes for a quantity: setup plus labour time per unit, the run time when no labour time is set.
     */
    public function laborMinutesFor(string $quantity): string
    {
        $perUnit = Money::isPositive($this->labor_time_minutes_per_unit) ? $this->labor_time_minutes_per_unit : $this->run_time_minutes_per_unit;

        return Money::add($this->setup_time_minutes, Money::mul($perUnit, $quantity));
    }
}
