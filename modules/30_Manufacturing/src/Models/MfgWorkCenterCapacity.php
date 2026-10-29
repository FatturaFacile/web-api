<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function workCenter(): BelongsTo
    {
        return $this->belongsTo(MfgWorkCenter::class, 'work_center_id');
    }
}
