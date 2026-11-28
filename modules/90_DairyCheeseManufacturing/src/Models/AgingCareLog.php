<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A scheduled or executed care operation (brining, turning, brushing, rind washing, piercing, expert grading).
 *
 * @property string $care_type
 * @property string $status
 * @property string $scope
 * @property string $target_id
 * @property Carbon $scheduled_for
 * @property Carbon|null $executed_at
 * @property int $wheels_affected
 * @property string|null $performed_by
 * @property string|null $notes
 */
final class AgingCareLog extends BaseEnterpriseModel
{
    protected $table = 'dairy_aging_care_logs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'executed_at' => 'datetime',
            'wheels_affected' => 'integer',
        ];
    }
}
