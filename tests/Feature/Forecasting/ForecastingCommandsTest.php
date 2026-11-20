<?php

declare(strict_types=1);

use Database\Seeders\AiForecastingDemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Forecasting\Application\Commands\GenerateSkuDemandForecastCommand;
use Modules\Forecasting\Application\Commands\RunAiLiquiditySimulationCommand;
use Modules\Forecasting\Application\DTOs\WhatIfScenarioDto;
use Modules\Forecasting\Application\Handlers\GenerateSkuDemandForecastHandler;
use Modules\Forecasting\Application\Handlers\RunAiLiquiditySimulationHandler;
use Modules\Forecasting\Domain\Contracts\ForecastingEngineInterface;
use Modules\Forecasting\Domain\Exceptions\ForecastingException;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;
use Modules\Forecasting\Infrastructure\Services\HoltWintersForecastingEngine;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiForecastingDemoSeeder::class);
});

test('the nightly commands are scheduled and registered', function (): void {
    $events = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    foreach (['forecasting:train-payment-models', 'forecasting:refresh-liquidity', 'forecasting:generate-sku-demand'] as $command) {
        expect($events->contains(fn (string $line): bool => str_contains($line, $command)))->toBeTrue("{$command} is not scheduled");
    }

    expect(array_keys(Artisan::all()))->toContain('forecasting:verify-e2e');
});

test('the nightly commands run for every active tenant', function (): void {
    $this->artisan('forecasting:train-payment-models', ['--tenant' => AiForecastingDemoSeeder::demoId('IT', 'tenant')])->assertSuccessful();
    $this->artisan('forecasting:refresh-liquidity', ['--tenant' => AiForecastingDemoSeeder::demoId('IT', 'tenant')])->assertSuccessful();
    $this->artisan('forecasting:generate-sku-demand', ['--tenant' => AiForecastingDemoSeeder::demoId('IT', 'tenant')])->assertSuccessful();

    expect(PaymentBehaviorModel::withoutGlobalScopes()->where('tenant_id', AiForecastingDemoSeeder::demoId('IT', 'tenant'))->count())->toBe(4)
        ->and(LiquidityForecastRun::withoutGlobalScopes()->where('tenant_id', AiForecastingDemoSeeder::demoId('IT', 'tenant'))->pluck('horizon_days')->sort()->values()->all())->toBe([91, 365])
        // Three SKUs for the current month plus the three seeded closed months of SKU-003.
        ->and(SkuDemandForecastRecord::withoutGlobalScopes()->where('tenant_id', AiForecastingDemoSeeder::demoId('IT', 'tenant'))->count())->toBe(6);

    // Running the tenants again updates the rows instead of adding forecasts.
    $this->artisan('forecasting:generate-sku-demand')->assertSuccessful();

    expect(SkuDemandForecastRecord::withoutGlobalScopes()->count())->toBe(12);
});

test('the nightly command reports a failing tenant', function (): void {
    $this->artisan('forecasting:train-payment-models', ['--tenant' => '00000000-0000-4000-8000-000000000000'])->assertFailed();
});

test('the liquidity handler refuses a horizon outside the supported range and an unknown tenant', function (): void {
    $handler = app(RunAiLiquiditySimulationHandler::class);

    expect(fn () => $handler->handle(new RunAiLiquiditySimulationCommand(AiForecastingDemoSeeder::demoId('CH', 'tenant'), 0)))->toThrow(ForecastingException::class)
        ->and(fn () => $handler->handle(new RunAiLiquiditySimulationCommand('00000000-0000-4000-8000-000000000000', 91)))->toThrow(ForecastingException::class);
});

test('without training the receivables fall on their due dates, and revenue growth raises the balance', function (): void {
    $handler = app(RunAiLiquiditySimulationHandler::class);
    $tenantId = AiForecastingDemoSeeder::demoId('CH', 'tenant');

    $baseline = $handler->handle(new RunAiLiquiditySimulationCommand($tenantId, 91, null, false));
    $growth = $handler->handle(new RunAiLiquiditySimulationCommand($tenantId, 91, new WhatIfScenarioDto(revenueGrowthPercentage: 20.0), false));
    $last = fn (LiquidityForecastRun $run): float => $run->daily_projections_json[90]['balance']['p50'];

    expect($last($growth))->toBeGreaterThan($last($baseline))
        ->and($baseline->what_if_parameters_json)->toBeNull()
        ->and($growth->what_if_parameters_json['revenue_growth_percentage'])->toEqual(20);
});

test('the demand handler rejects an invalid horizon and skips products with too little history', function (): void {
    $handler = app(GenerateSkuDemandForecastHandler::class);
    $tenantId = AiForecastingDemoSeeder::demoId('CH', 'tenant');

    expect(fn () => $handler->handle(new GenerateSkuDemandForecastCommand($tenantId, periodsAhead: 0)))->toThrow(ForecastingException::class);

    // Only SKU-001 sells in the window of the last 4 months of the seeded history... but at least 6 months are required.
    expect(fn () => $handler->handle(new GenerateSkuDemandForecastCommand($tenantId, historyMonths: 3)))->toThrow(ForecastingException::class);

    DB::table('sales_orders')->where('tenant_id', $tenantId)->update(['status' => 'draft']);

    expect($handler->handle(new GenerateSkuDemandForecastCommand($tenantId)))->toBe([]);
});

test('a forecast over several months spans the periods and widens the bounds', function (): void {
    $tenantId = AiForecastingDemoSeeder::demoId('CH', 'tenant');
    $productId = AiForecastingDemoSeeder::demoId('CH', 'product-SKU-002');
    $handler = app(GenerateSkuDemandForecastHandler::class);

    $one = $handler->handle(new GenerateSkuDemandForecastCommand($tenantId, $productId, periodsAhead: 1))[0];
    $three = $handler->handle(new GenerateSkuDemandForecastCommand($tenantId, $productId, periodsAhead: 3))[0];

    expect((float) $three->predicted_demand_qty)->toEqualWithDelta(3 * (float) $one->predicted_demand_qty, 0.01)
        ->and($three->forecast_period_end->gt($one->forecast_period_end))->toBeTrue()
        ->and((float) $three->confidence_upper_bound - (float) $three->predicted_demand_qty)->toBeGreaterThan((float) $one->confidence_upper_bound - (float) $one->predicted_demand_qty);
});

test('the forecasting engine is a replaceable binding', function (): void {
    expect(app(ForecastingEngineInterface::class))->toBeInstanceOf(HoltWintersForecastingEngine::class);
});
