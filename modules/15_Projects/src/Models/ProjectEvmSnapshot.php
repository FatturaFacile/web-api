<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The earned value figures of a project on a given day (PV, EV, AC and the indices derived from them), kept so the
 * trend of CPI and SPI can be charted. One snapshot per project and day; a recalculation on the same day replaces it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property CarbonInterface $snapshot_date
 * @property string $percent_complete
 * @property string $planned_percent_complete
 * @property string $budget_at_completion
 * @property string $planned_value
 * @property string $earned_value
 * @property string $actual_cost
 * @property string $cost_variance
 * @property string $schedule_variance
 * @property string|null $cost_performance_index
 * @property string|null $schedule_performance_index
 * @property string $estimate_at_completion
 * @property string $estimate_to_complete
 * @property string $variance_at_completion
 * @property string $health_status
 * @property string|null $calculated_by
 */
final class ProjectEvmSnapshot extends BaseEnterpriseModel
{
    protected $table = 'project_evm_snapshots';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'percent_complete' => 'decimal:2',
            'planned_percent_complete' => 'decimal:2',
            'budget_at_completion' => 'decimal:2',
            'planned_value' => 'decimal:2',
            'earned_value' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'cost_variance' => 'decimal:2',
            'schedule_variance' => 'decimal:2',
            'cost_performance_index' => 'decimal:4',
            'schedule_performance_index' => 'decimal:4',
            'estimate_at_completion' => 'decimal:2',
            'estimate_to_complete' => 'decimal:2',
            'variance_at_completion' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
