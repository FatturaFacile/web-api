<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The audit entry of an escalation step fired on a ticket. Rows are written once and never updated.
 *
 * @property string $ticket_id
 * @property string $step_id
 * @property string $target
 * @property int $threshold_percent
 * @property string $action
 * @property int $elapsed_percent
 * @property array<string, mixed>|null $outcome
 * @property Carbon|null $fired_at
 */
final class TicketEscalationLog extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_escalation_logs';

    protected function casts(): array
    {
        return [
            'threshold_percent' => 'integer',
            'elapsed_percent' => 'integer',
            'outcome' => 'array',
            'fired_at' => 'datetime',
        ];
    }
}
