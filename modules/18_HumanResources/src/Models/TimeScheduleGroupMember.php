<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A user in a schedule group for a period.
 */
final class TimeScheduleGroupMember extends BaseEnterpriseModel
{
    protected $table = 'time_schedule_group_members';

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }
}
