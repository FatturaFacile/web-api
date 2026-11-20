<?php

declare(strict_types=1);

namespace Modules\Forecasting\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;

/**
 * @mixin LiquidityForecastRun
 */
final class LiquidityForecastRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'generated_at' => $this->generated_at->toIso8601String(),
            'horizon_days' => $this->horizon_days,
            'currency' => $this->currency,
            'starting_cash_balance' => (float) $this->starting_cash_balance,
            'trough' => $this->toDomain()->cashTrough(),
            'daily_projections' => $this->daily_projections_json,
            'key_drivers' => $this->key_drivers_json,
            'what_if_parameters' => $this->what_if_parameters_json,
        ];
    }
}
