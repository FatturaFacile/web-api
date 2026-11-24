<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

final class Forecast extends BaseEnterpriseModel
{
    protected $table = 'crm_forecasts';

    protected function casts(): array
    {
        return [
            'pipeline_amount' => 'decimal:2',
            'best_case_amount' => 'decimal:2',
            'commit_amount' => 'decimal:2',
            'closed_amount' => 'decimal:2',
            'weighted_amount' => 'decimal:2',
            'quota_amount' => 'decimal:2',
            'attainment_percent' => 'decimal:2',
        ];
    }
}
