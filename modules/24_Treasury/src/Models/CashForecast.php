<?php

declare(strict_types=1);

namespace Modules\Treasury\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CashForecast extends BaseEnterpriseModel
{
    protected $table = 'cash_forecasts';

    protected function casts(): array
    {
        return [
            'calculation_date' => 'date',
            'horizon_days' => 'integer',
            'opening_cash_balance' => 'decimal:2',
            'projected_inflows' => 'decimal:2',
            'projected_outflows' => 'decimal:2',
            'closing_cash_balance' => 'decimal:2',
            'min_liquidity_threshold' => 'decimal:2',
            'deficit_detected' => 'boolean',
            'deficit_date' => 'date',
        ];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(ForecastScenario::class, 'forecast_scenario_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CashForecastLine::class, 'cash_forecast_id');
    }

    public function getOpeningLiquidityAttribute(): float
    {
        return (float) $this->opening_cash_balance;
    }

    public function getTotalInflowsAttribute(): float
    {
        return (float) $this->projected_inflows;
    }

    public function getTotalOutflowsAttribute(): float
    {
        return (float) $this->projected_outflows;
    }

    public function getNetCashFlowAttribute(): float
    {
        return (float) $this->projected_inflows - (float) $this->projected_outflows;
    }

    public function getClosingLiquidityAttribute(): float
    {
        return (float) $this->closing_cash_balance;
    }

    public function getHasLiquidityDeficitAttribute(): bool
    {
        return (bool) $this->deficit_detected;
    }

    public function getDeficitAmountAttribute(): float
    {
        return (float) $this->closing_cash_balance < 0 ? abs((float) $this->closing_cash_balance) : 0.0;
    }
}
