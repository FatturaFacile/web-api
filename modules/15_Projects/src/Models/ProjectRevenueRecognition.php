<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectRevenueRecognition extends BaseEnterpriseModel
{
    protected $table = 'project_revenue_recognition';

    protected function casts(): array
    {
        return [
            'accounting_period_date' => 'date',
            'cumulative_actual_cost' => 'decimal:2',
            'estimated_total_cost_eac' => 'decimal:2',
            'cost_completion_percentage' => 'decimal:2',
            'cumulative_recognized_revenue' => 'decimal:2',
            'period_revenue_adjustment' => 'decimal:2',
            'is_posted_to_gl' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
