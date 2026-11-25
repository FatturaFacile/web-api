<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SalesBoundedContext\Domain\Enums\ForecastCategory;

final class SbcOpportunityForecast extends BaseEnterpriseModel
{
    protected $table = 'sbc_opportunity_forecasts';

    protected function casts(): array
    {
        return [
            'forecast_category' => ForecastCategory::class,
            'win_probability_pct' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'weighted_amount' => 'decimal:2',
            'sales_quota_target' => 'decimal:2',
            'quota_attainment_pct' => 'decimal:2',
            'expected_close_date' => 'date',
        ];
    }
}
