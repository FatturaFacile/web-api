<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Production execution record over a period of a work centre or machine: downtime, and good, scrapped and reworked
 * units. Entered by hand, by the shop floor (MES) or by Industria 4.0 / Transizione 5.0 telemetry.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $work_center_id
 * @property string|null $machine_center_id
 * @property string $source
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $planned_downtime_minutes
 * @property string $unplanned_downtime_minutes
 * @property string|null $loss_category
 * @property string $good_units
 * @property string $scrap_units
 * @property string $rework_units
 * @property string|null $notes
 */
final class MfgOeeLog extends BaseEnterpriseModel
{
    protected $table = 'mfg_oee_logs';

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'planned_downtime_minutes' => 'decimal:3',
            'unplanned_downtime_minutes' => 'decimal:3',
            'good_units' => 'decimal:4',
            'scrap_units' => 'decimal:4',
            'rework_units' => 'decimal:4',
        ];
    }
}
