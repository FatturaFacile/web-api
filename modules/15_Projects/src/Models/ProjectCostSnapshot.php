<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectCostSnapshot extends BaseEnterpriseModel
{
    protected $table = 'project_cost_snapshots';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'total_material_cost' => 'decimal:2',
            'total_labour_cost' => 'decimal:2',
            'total_supplier_cost' => 'decimal:2',
            'total_fleet_cost' => 'decimal:2',
            'total_expense_cost' => 'decimal:2',
            'cumulative_total_cost' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
