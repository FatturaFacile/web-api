<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A day of the weekly calendar of a work centre: the gross hours available on that weekday (ISO, 1 = Monday).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $work_center_id
 * @property int $weekday
 * @property string $available_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgWorkCenterShift extends BaseEnterpriseModel
{
    protected $table = 'mfg_work_center_shifts';

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'available_hours' => 'decimal:2',
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
