<?php

declare(strict_types=1);

namespace Modules\DataExport\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Recurring export of one dataset: each due day it creates an {@see ExportJob} for the period it names.
 */
final class ExportSchedule extends BaseEnterpriseModel
{
    /**
     * @var list<string>
     */
    public const array Frequencies = ['daily', 'weekly', 'monthly'];

    /**
     * @var list<string>
     */
    public const array Periods = ['all', 'previous_month', 'year_to_date'];

    protected $table = 'export_schedules';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'next_run_on' => 'date:Y-m-d',
            'last_run_at' => 'datetime',
        ];
    }
}
