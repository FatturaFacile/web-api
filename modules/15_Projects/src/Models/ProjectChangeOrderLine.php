<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cost category line of a change order: the budget delta it applies to that category once approved.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_change_order_id
 * @property string $cost_category
 * @property string $description
 * @property string $quantity_delta
 * @property string $unit_cost_delta
 * @property string $total_cost_delta
 * @property string $revenue_delta
 */
final class ProjectChangeOrderLine extends BaseEnterpriseModel
{
    protected $table = 'project_change_order_lines';

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:4',
            'unit_cost_delta' => 'decimal:4',
            'total_cost_delta' => 'decimal:2',
            'revenue_delta' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ProjectChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ProjectChangeOrder::class, 'project_change_order_id');
    }
}
