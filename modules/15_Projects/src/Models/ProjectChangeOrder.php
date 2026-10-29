<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProjectChangeOrderLine::class, 'project_change_order_id');
    }
}
