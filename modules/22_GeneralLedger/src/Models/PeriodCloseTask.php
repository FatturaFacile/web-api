<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A task of the close checklist of a fiscal period written by the users (bank reconciliation signed off, inventory
 * count booked, ...). A blocking task must be done or waived before the period can be closed.
 */
final class PeriodCloseTask extends BaseEnterpriseModel
{
    protected $table = 'gl_period_close_tasks';

    protected function casts(): array
    {
        return [
            'is_blocking' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
}
