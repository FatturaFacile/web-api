<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A period-end work-in-progress valuation of a project (OIC 23 / IFRS 15 / Swiss GAAP FER 22). A positive
 * `wip_asset_or_deferred_revenue_diff` is a contract asset (revenue recognised above what was billed); a negative one is
 * a contract liability (billing in excess, deferred revenue).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $project_id
 * @property string|null $revenue_recognition_id
 * @property CarbonInterface $period_end_date
 * @property string $valuation_method
 * @property string $accounting_standard
 * @property string $cumulative_actual_cost_to_date
 * @property string $estimated_total_cost_at_completion
 * @property string $completion_ratio
 * @property string $recognized_revenue_to_date
 * @property string $billed_to_date
 * @property string $wip_asset_or_deferred_revenue_diff
 * @property string $expected_loss_provision
 * @property string|null $gl_journal_entry_id
 * @property string $status
 */
final class ProjectWipValuationRun extends BaseEnterpriseModel
{
    protected $table = 'project_wip_valuation_runs';

    protected function casts(): array
    {
        return [
            'period_end_date' => 'date',
            'cumulative_actual_cost_to_date' => 'decimal:4',
            'estimated_total_cost_at_completion' => 'decimal:4',
            'completion_ratio' => 'decimal:6',
            'recognized_revenue_to_date' => 'decimal:4',
            'billed_to_date' => 'decimal:4',
            'wip_asset_or_deferred_revenue_diff' => 'decimal:4',
            'expected_loss_provision' => 'decimal:4',
        ];
    }
}
