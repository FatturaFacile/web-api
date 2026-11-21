<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\KpiDashboards\Domain\Enums\KpiCode;

/**
 * A threshold breach. While the cooldown runs a repeated breach of the same rule and severity does not raise another one.
 *
 * @property string $id
 * @property string $rule_id
 * @property KpiCode $kpi_code
 * @property string $severity
 * @property string $observed_value
 * @property string $threshold_value
 * @property string $status open or acknowledged
 * @property list<string>|null $dispatched_channels_json
 * @property Carbon $triggered_at
 * @property Carbon|null $cooldown_until
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_by
 */
final class KpiThresholdAlertLog extends BaseEnterpriseModel
{
    protected $table = 'kpi_threshold_alert_logs';

    protected function casts(): array
    {
        return [
            'kpi_code' => KpiCode::class,
            'dispatched_channels_json' => 'array',
            'triggered_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
