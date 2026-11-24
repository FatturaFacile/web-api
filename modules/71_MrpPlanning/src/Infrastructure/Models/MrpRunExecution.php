<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\MrpPlanning\Domain\Enums\CapacitySchedulingMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunMode;
use Modules\MrpPlanning\Domain\Enums\MrpRunStatus;
use Modules\MrpPlanning\Domain\Enums\SchedulingDirection;

/**
 * One execution of the MRP planning engine with the parameters it ran with and its KPI summary.
 *
 * @property string $run_code
 * @property MrpRunStatus $status
 * @property MrpRunMode $run_mode
 * @property int $planning_horizon_days
 * @property CapacitySchedulingMode $capacity_scheduling_mode
 * @property SchedulingDirection $scheduling_direction
 * @property bool $include_cross_border_customs_lead_time
 * @property bool $apply_supplier_shutdown_calendars
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $execution_duration_ms
 * @property array<string, mixed>|null $summary_metrics
 * @property string|null $error_message
 */
final class MrpRunExecution extends BaseEnterpriseModel
{
    protected $table = 'mrp_run_executions';

    protected function casts(): array
    {
        return [
            'status' => MrpRunStatus::class,
            'run_mode' => MrpRunMode::class,
            'capacity_scheduling_mode' => CapacitySchedulingMode::class,
            'scheduling_direction' => SchedulingDirection::class,
            'planning_horizon_days' => 'integer',
            'include_cross_border_customs_lead_time' => 'boolean',
            'apply_supplier_shutdown_calendars' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'execution_duration_ms' => 'integer',
            'summary_metrics' => 'array',
        ];
    }

    /**
     * @return HasMany<MrpActionProposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(MrpActionProposal::class, 'mrp_run_id');
    }
}
