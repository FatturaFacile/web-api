<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ForecastScenario extends BaseEnterpriseModel
{
    protected $table = 'forecast_scenarios';

    protected function casts(): array
    {
        return [
            'is_baseline' => 'boolean',
            'collection_delay_days' => 'integer',
            'disbursement_acceleration_days' => 'integer',
            'sales_growth_factor' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(CashForecast::class, 'forecast_scenario_id');
    }
}
