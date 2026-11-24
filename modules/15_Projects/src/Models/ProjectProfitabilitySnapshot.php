<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectProfitabilitySnapshot extends BaseEnterpriseModel
{
    protected $table = 'project_profitability_snapshots';

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'contract_revenue' => 'decimal:2',
            'invoiced_revenue' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'net_profit_amount' => 'decimal:2',
            'profit_margin_pct' => 'decimal:2',
            'cpi' => 'decimal:2',
            'spi' => 'decimal:2',
            'progress_percentage' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
