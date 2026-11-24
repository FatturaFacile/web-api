<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A planned shutdown of a working calendar over a range of days, both ends included (plant holidays, revisions).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $working_calendar_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string|null $reason
 */
final class MfgWorkingCalendarClosure extends BaseEnterpriseModel
{
    protected $table = 'mfg_working_calendar_closures';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }
}
