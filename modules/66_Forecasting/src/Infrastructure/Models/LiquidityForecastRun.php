<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Models;

use DateTimeImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Forecasting\Domain\Entities\AiLiquidityForecastRun;

/**
 * Persisted liquidity projection.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $generated_at
 * @property int $horizon_days
 * @property string $currency
 * @property string $starting_cash_balance
 * @property list<array<string, mixed>> $daily_projections_json
 * @property array<string, mixed> $key_drivers_json
 * @property array<string, mixed>|null $what_if_parameters_json
 */
final class LiquidityForecastRun extends BaseEnterpriseModel
{
    protected $table = 'ai_liquidity_forecast_runs';

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'horizon_days' => 'integer',
            'starting_cash_balance' => 'decimal:2',
            'daily_projections_json' => 'array',
            'key_drivers_json' => 'array',
            'what_if_parameters_json' => 'array',
        ];
    }

    public function toDomain(): AiLiquidityForecastRun
    {
        return new AiLiquidityForecastRun(
            $this->id,
            $this->tenant_id,
            DateTimeImmutable::createFromInterface($this->generated_at),
            $this->horizon_days,
            (float) $this->starting_cash_balance,
            $this->daily_projections_json,
            $this->key_drivers_json,
            $this->what_if_parameters_json,
        );
    }
}
