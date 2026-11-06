<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of employees under the same tolerance, rounding and allowance rules.
 */
final class TimeScheduleGroup extends BaseEnterpriseModel
{
    protected $table = 'time_schedule_groups';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<TimeScheduleGroupRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(TimeScheduleGroupRule::class, 'schedule_group_id')->orderByDesc('valid_from');
    }
}
