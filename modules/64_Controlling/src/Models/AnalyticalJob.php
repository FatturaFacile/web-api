<?php

declare(strict_types=1);

namespace Modules\Controlling\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Controlling record of the table analytical_jobs.
 *
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $status
 * @property string $contract_value
 * @property string $estimated_cost_budget
 */
final class AnalyticalJob extends BaseEnterpriseModel
{
    protected $table = 'analytical_jobs';

    public const array Statuses = ['planned', 'in_progress', 'completed', 'closed'];

    /**
     * @return BelongsTo<AnalyticalCostCenter, $this>
     */
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(AnalyticalCostCenter::class, 'cost_center_id');
    }

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }
}
