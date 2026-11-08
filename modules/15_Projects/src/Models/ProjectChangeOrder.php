<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A change order (variante): a change of scope agreed with the customer that, once approved, moves the contract value,
 * the cost budget by category and the planned end date of the project.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $project_id
 * @property string $order_number
 * @property string $title
 * @property string $scope_description
 * @property string $cost_impact_amount
 * @property string $revenue_impact_amount
 * @property int $schedule_impact_days
 * @property string $status
 * @property CarbonInterface|null $approved_by_client_at
 * @property string|null $approved_by
 * @property CarbonInterface|null $applied_at
 * @property CarbonInterface|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $created_by
 */
final class ProjectChangeOrder extends BaseEnterpriseModel
{
    protected $table = 'project_change_orders';

    protected function casts(): array
    {
        return [
            'cost_impact_amount' => 'decimal:2',
            'revenue_impact_amount' => 'decimal:2',
            'schedule_impact_days' => 'integer',
            'approved_by_client_at' => 'datetime',
            'applied_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return HasMany<ProjectChangeOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProjectChangeOrderLine::class, 'project_change_order_id');
    }
}
