<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The financial and earned value figures of a project, or of one of its WBS nodes, on a given day (Mod 082). One
 * snapshot per node and day; a recomputation on the same day replaces it.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string|null $wbs_node_id
 * @property CarbonInterface $snapshot_date
 * @property string $currency_code
 * @property string $budget_at_completion_bac
 * @property string $planned_value_pv
 * @property string $earned_value_ev
 * @property string $actual_cost_ac
 * @property string $committed_cost
 * @property string $cost_variance_cv
 * @property string $schedule_variance_sv
 * @property string|null $cost_performance_index_cpi
 * @property string|null $schedule_performance_index_spi
 * @property string $estimate_at_completion_eac
 * @property string $estimate_to_complete_etc
 * @property string $variance_at_completion_vac
 * @property string|null $budget_absorption_percent
 * @property string $recognized_revenue
 * @property string $gross_margin_amount
 * @property string|null $gross_margin_percent
 * @property string|null $manual_etc_override
 * @property string $snapshot_trigger
 * @property string $calculation_version
 * @property array<string, mixed>|null $reconciliation
 */
final class ProjectFinancialSnapshot extends BaseEnterpriseModel
{
    protected $table = 'project_financial_snapshots';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'budget_at_completion_bac' => 'decimal:4',
            'planned_value_pv' => 'decimal:4',
            'earned_value_ev' => 'decimal:4',
            'actual_cost_ac' => 'decimal:4',
            'committed_cost' => 'decimal:4',
            'cost_variance_cv' => 'decimal:4',
            'schedule_variance_sv' => 'decimal:4',
            'cost_performance_index_cpi' => 'decimal:4',
            'schedule_performance_index_spi' => 'decimal:4',
            'estimate_at_completion_eac' => 'decimal:4',
            'estimate_to_complete_etc' => 'decimal:4',
            'variance_at_completion_vac' => 'decimal:4',
            'budget_absorption_percent' => 'decimal:2',
            'recognized_revenue' => 'decimal:4',
            'gross_margin_amount' => 'decimal:4',
            'gross_margin_percent' => 'decimal:2',
            'manual_etc_override' => 'decimal:4',
            'reconciliation' => 'array',
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
