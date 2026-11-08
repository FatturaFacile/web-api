<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One revenue recognition run of a project for an accounting date: the cumulative revenue and cost of revenue that the
 * method recognises, the part already billed, and the period adjustments posted to the general ledger.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property CarbonInterface $accounting_period_date
 * @property string|null $recognition_method
 * @property string $contract_value
 * @property string $cumulative_actual_cost
 * @property string $estimated_total_cost_eac
 * @property string $cost_completion_percentage
 * @property string $cumulative_recognized_revenue
 * @property string $period_revenue_adjustment
 * @property string $cumulative_billed_revenue
 * @property string $contract_asset_balance
 * @property string $period_contract_asset_adjustment
 * @property string $cumulative_cost_of_revenue
 * @property string $period_cost_of_revenue
 * @property bool $is_posted_to_gl
 * @property CarbonInterface|null $posted_at
 * @property string|null $recognized_by
 */
final class ProjectRevenueRecognition extends BaseEnterpriseModel
{
    protected $table = 'project_revenue_recognition';

    protected function casts(): array
    {
        return [
            'accounting_period_date' => 'date',
            'contract_value' => 'decimal:2',
            'cumulative_actual_cost' => 'decimal:2',
            'estimated_total_cost_eac' => 'decimal:2',
            'cost_completion_percentage' => 'decimal:2',
            'cumulative_recognized_revenue' => 'decimal:2',
            'period_revenue_adjustment' => 'decimal:2',
            'cumulative_billed_revenue' => 'decimal:2',
            'contract_asset_balance' => 'decimal:2',
            'period_contract_asset_adjustment' => 'decimal:2',
            'cumulative_cost_of_revenue' => 'decimal:2',
            'period_cost_of_revenue' => 'decimal:2',
            'is_posted_to_gl' => 'boolean',
            'posted_at' => 'datetime',
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
