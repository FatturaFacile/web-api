<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Record of the table apportionment_runs.
 *
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
final class ApportionmentRun extends BaseEnterpriseModel
{
    protected $table = 'apportionment_runs';

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'warnings' => 'array', 'consolidated_at' => 'datetime', 'reversed_at' => 'datetime', 'total_pool' => 'decimal:4', 'total_allocated' => 'decimal:4', 'total_unallocated' => 'decimal:4'];
    }

    /**
     * @return HasMany<ApportionmentRunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApportionmentRunStep::class, 'apportionment_run_id')->orderBy('step_order');
    }

    /**
     * @return HasMany<ApportionmentRunAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ApportionmentRunAllocation::class, 'apportionment_run_id')->orderBy('step_order');
    }
}
