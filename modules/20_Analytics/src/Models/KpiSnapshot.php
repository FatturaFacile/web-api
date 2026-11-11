<?php

declare(strict_types=1);

namespace Modules\Analytics\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The value of a heavy KPI on a day, materialised by the nightly refresh so dashboards read a row instead of scanning
 * invoices and ledger lines. Period KPIs hold the month to date at the snapshot day, snapshot KPIs the position at
 * that day.
 *
 * @property string $id
 * @property string $kpi_key
 * @property Carbon $snapshot_date
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property ?string $value
 * @property ?array<string, mixed> $details
 * @property Carbon $computed_at
 */
final class KpiSnapshot extends BaseEnterpriseModel
{
    protected $table = 'analytics_kpi_snapshots';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snapshot_date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'details' => 'array', 'computed_at' => 'datetime'];
    }
}
