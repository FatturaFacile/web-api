<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Treasury\Models\CashForecast;

/**
 * @mixin CashForecast
 */
final class CashForecastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'forecast_number' => $this->forecast_number,
            'calculation_date' => $this->calculation_date?->toDateString(),
            'horizon_days' => (int) $this->horizon_days,
            'opening_cash_balance' => (float) $this->opening_cash_balance,
            'projected_inflows' => (float) $this->projected_inflows,
            'projected_outflows' => (float) $this->projected_outflows,
            'closing_cash_balance' => (float) $this->closing_cash_balance,
            'min_liquidity_threshold' => (float) $this->min_liquidity_threshold,
            'deficit_detected' => (bool) $this->deficit_detected,
            'deficit_date' => $this->deficit_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
