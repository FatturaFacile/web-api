<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Read model: the minutes available and loaded on a work centre on a day of a run. A bucket is overloaded above 100 %.
 *
 * @property string $mrp_run_id
 * @property string $work_center_id
 * @property Carbon $bucket_date
 * @property string|null $shift_code
 * @property int $available_minutes
 * @property int $loaded_minutes
 * @property string $load_percentage
 * @property bool $is_overloaded
 * @property list<string>|null $assigned_proposal_ids
 */
final class MrpWorkCenterCapacityBucket extends BaseEnterpriseModel
{
    protected $table = 'mrp_work_center_capacity_buckets';

    protected function casts(): array
    {
        return [
            'bucket_date' => 'date',
            'available_minutes' => 'integer',
            'loaded_minutes' => 'integer',
            'load_percentage' => 'decimal:2',
            'is_overloaded' => 'boolean',
            'assigned_proposal_ids' => 'array',
        ];
    }
}
