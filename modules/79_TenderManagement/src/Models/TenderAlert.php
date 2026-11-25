<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\TenderManagement\Domain\Enums\AlertSeverity;

/**
 * An alert raised by the scheduled scan: a deadline milestone of a notice or an expiry milestone of a vault document.
 * The unique key (subject, type, milestone) makes the scan idempotent.
 *
 * @property string $subject_type
 * @property string $subject_id
 * @property string $alert_type
 * @property string $milestone
 * @property AlertSeverity $severity
 * @property string $message_key
 * @property array<string, mixed>|null $message_params
 * @property Carbon|null $due_at
 * @property Carbon $raised_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledged_by
 */
final class TenderAlert extends BaseEnterpriseModel
{
    protected $table = 'tnd_alerts';

    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'message_params' => 'array',
            'due_at' => 'datetime',
            'raised_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
