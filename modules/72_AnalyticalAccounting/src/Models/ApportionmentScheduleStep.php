<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Record of the table apportionment_schedule_steps.
 */
final class ApportionmentScheduleStep extends BaseEnterpriseModel
{
    protected $table = 'apportionment_schedule_steps';

    protected function casts(): array
    {
        return ['include_descendants' => 'boolean', 'fixed_weights_json' => 'array', 'target_filter_json' => 'array', 'step_order' => 'integer'];
    }
}
