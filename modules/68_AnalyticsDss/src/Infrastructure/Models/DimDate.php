<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Analytical data mart table `dim_date` (Mod 093). Rebuilt from the operational data by the projectors, never edited by hand.
 */
final class DimDate extends BaseEnterpriseModel
{
    protected $table = 'dim_date';

    protected function casts(): array
    {
        return ['date' => 'date', 'is_weekend' => 'boolean', 'is_ch_holiday' => 'boolean', 'is_it_holiday' => 'boolean'];
    }
}
