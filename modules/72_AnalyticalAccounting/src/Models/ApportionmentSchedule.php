<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Record of the table apportionment_schedules.
 */
final class ApportionmentSchedule extends BaseEnterpriseModel
{
    protected $table = 'apportionment_schedules';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<ApportionmentScheduleStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ApportionmentScheduleStep::class, 'apportionment_schedule_id')->orderBy('step_order');
    }
}
