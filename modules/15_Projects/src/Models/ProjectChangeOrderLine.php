<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ProjectChangeOrder::class, 'project_change_order_id');
    }
}
