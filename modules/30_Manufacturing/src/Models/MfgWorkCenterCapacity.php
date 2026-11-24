<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dated capacity exception of a work centre (holiday, maintenance stop, extra shift): the gross hours of that day
 * replace the weekly calendar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $work_center_id
 * @property Carbon $calendar_date
 * @property string $available_hours
 * @property string $booked_hours
 * @property string $utilization_rate
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgWorkCenterCapacity extends BaseEnterpriseModel
{
    protected $table = 'mfg_work_center_capacities';

    protected function casts(): array
    {
        return [
            'calendar_date' => 'date',
            'available_hours' => 'decimal:2',
            'booked_hours' => 'decimal:2',
            'utilization_rate' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<MfgWorkCenter, $this>
     */
    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }
}
