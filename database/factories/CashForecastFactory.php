<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\CashForecast;
use Modules\Treasury\Models\ForecastScenario;

/**
 * @extends Factory<CashForecast>
 */
final class CashForecastFactory extends Factory
{
    protected $model = CashForecast::class;

    public function definition(): array
    {
        $tenantId = (string) Str::uuid7();
        $scenario = ForecastScenario::first();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => (string) Str::uuid7(),
            'scenario_id' => $scenario?->id,
            'forecast_reference' => 'CFC-'.date('Ymd').'-'.strtoupper(Str::random(6)),
            'calculation_date' => date('Y-m-d'),
            'horizon_days' => 90,
            'opening_liquidity' => 500000.00,
            'total_inflows' => 300000.00,
            'total_outflows' => 200000.00,
            'net_cash_flow' => 100000.00,
            'closing_liquidity' => 600000.00,
            'minimum_liquidity_threshold' => 50000.00,
            'has_liquidity_deficit' => false,
            'deficit_amount' => 0.00,
            'first_deficit_date' => null,
        ];
    }
}
