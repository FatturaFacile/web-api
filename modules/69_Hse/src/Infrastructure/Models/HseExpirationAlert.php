<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * Log of a sent expiration alert: the daily scan sends each threshold once (overdue alerts: once per day).
 *
 * @property string $subject_type certificate, medical_visit or ppe
 * @property string $subject_id
 * @property int $threshold_days 60, 30, 7, or -1 for the daily overdue escalation
 * @property Carbon $alert_date
 * @property int $recipients_count
 */
final class HseExpirationAlert extends BaseEnterpriseModel
{
    protected $table = 'hse_expiration_alerts';

    protected function casts(): array
    {
        return ['alert_date' => 'date', 'threshold_days' => 'integer', 'recipients_count' => 'integer'];
    }
}
