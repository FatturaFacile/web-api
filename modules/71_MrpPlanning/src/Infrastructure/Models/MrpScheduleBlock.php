<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * One routing operation of a planned production order placed on a work centre; segments are the minutes it loads per day.
 *
 * @property string $mrp_run_id
 * @property string $proposal_id
 * @property string $work_center_id
 * @property string|null $original_work_center_id
 * @property int $operation_sequence
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property int $minutes
 * @property list<array{date: string, minutes: int}>|null $segments
 * @property bool $is_manual
 */
final class MrpScheduleBlock extends BaseEnterpriseModel
{
    protected $table = 'mrp_schedule_blocks';

    protected function casts(): array
    {
        return [
            'operation_sequence' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'minutes' => 'integer',
            'segments' => 'array',
            'is_manual' => 'boolean',
        ];
    }
}
