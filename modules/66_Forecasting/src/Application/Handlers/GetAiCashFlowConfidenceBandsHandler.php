<?php

declare(strict_types=1);

namespace Modules\Forecasting\Application\Handlers;

use Modules\Forecasting\Application\Queries\GetAiCashFlowConfidenceBandsQuery;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;
use Modules\Forecasting\Infrastructure\Services\ForecastingTenantRunner;

/**
 * Reshapes a stored liquidity run into the arrays a chart needs.
 */
final readonly class GetAiCashFlowConfidenceBandsHandler
{
    public function __construct(private ForecastingTenantRunner $runner) {}

    /**
     * @return array<string, mixed>|null null when the tenant has no run
     */
    public function handle(GetAiCashFlowConfidenceBandsQuery $query): ?array
    {
        return $this->runner->run($query->tenantId, function () use ($query): ?array {
            $runs = LiquidityForecastRun::query()->where('tenant_id', $query->tenantId);

            if ($query->runId !== null) {
                $runs->where('id', $query->runId);
            }

            if ($query->horizonDays !== null) {
                $runs->where('horizon_days', $query->horizonDays);
            }

            $run = $runs->orderByDesc('generated_at')->orderByDesc('id')->first();

            return $run === null ? null : $this->series($run);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function series(LiquidityForecastRun $run): array
    {
        $dates = [];
        $p10 = [];
        $p50 = [];
        $p90 = [];
        $events = [];

        foreach ($run->daily_projections_json as $bucket) {
            $dates[] = $bucket['date'];
            $p10[] = $bucket['balance']['p10'];
            $p50[] = $bucket['balance']['p50'];
            $p90[] = $bucket['balance']['p90'];

            foreach ($bucket['statutory_outflows'] as $event) {
                $events[] = $event;
            }
        }

        return [
            'run_id' => $run->id,
            'generated_at' => $run->generated_at->toIso8601String(),
            'horizon_days' => $run->horizon_days,
            'currency' => $run->currency,
            'starting_cash_balance' => (float) $run->starting_cash_balance,
            'dates' => $dates,
            'p10' => $p10,
            'p50' => $p50,
            'p90' => $p90,
            'statutoryEvents' => $events,
            'keyDrivers' => $run->key_drivers_json,
            'whatIf' => $run->what_if_parameters_json,
            'trough' => $run->toDomain()->cashTrough(),
        ];
    }
}
