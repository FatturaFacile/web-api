<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * One step of the trail of a report: who did what, and the status change it caused.
 *
 * @property string $expense_report_id
 * @property string|null $expense_line_id
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $actor_id
 * @property array<string, mixed>|null $payload
 */
final class ExpenseAuditLog extends BaseEnterpriseModel
{
    protected $table = 'exm_audit_logs';

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
