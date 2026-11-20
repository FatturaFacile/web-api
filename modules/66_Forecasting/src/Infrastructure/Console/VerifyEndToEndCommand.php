<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Console;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\AiForecastingDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Forecasting\Application\Commands\ApplyAiRecommendedReorderRulesCommand;
use Modules\Forecasting\Application\Commands\GenerateSkuDemandForecastCommand;
use Modules\Forecasting\Application\Commands\RunAiLiquiditySimulationCommand;
use Modules\Forecasting\Application\Commands\TrainCustomerPaymentDelayModelCommand;
use Modules\Forecasting\Application\DTOs\WhatIfScenarioDto;
use Modules\Forecasting\Application\Handlers\ApplyAiRecommendedReorderRulesHandler;
use Modules\Forecasting\Application\Handlers\GenerateSkuDemandForecastHandler;
use Modules\Forecasting\Application\Handlers\GetSkuForecastVsActualAccuracyHandler;
use Modules\Forecasting\Application\Handlers\RunAiLiquiditySimulationHandler;
use Modules\Forecasting\Application\Handlers\TrainCustomerPaymentDelayModelHandler;
use Modules\Forecasting\Application\Queries\GetSkuForecastVsActualAccuracyQuery;
use Modules\Forecasting\Domain\Entities\SkuDemandForecast;
use Modules\Forecasting\Domain\Enums\PaymentRiskTier;
use Modules\Forecasting\Infrastructure\Models\InvoiceExpectedCollection;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;
use Modules\Forecasting\Infrastructure\Models\PaymentBehaviorModel;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;
use Symfony\Component\Process\Process;

/**
 * Local end-to-end check of the forecasting lifecycle on a fresh SQLite database: seed, train the payment models, project
 * liquidity with the CH/IT statutory calendar and a what-if, forecast demand, check accuracy and apply the reorder rules,
 * then prove the tenants are isolated. The checks run against the demo data of AiForecastingDemoSeeder.
 */
final class VerifyEndToEndCommand extends Command
{
    protected $signature = 'forecasting:verify-e2e
        {--skip-migrate : Keep the current database and only (re)seed the demo data}
        {--frontend= : Path of web-frontend: runs npm run build there as the last gate}';

    protected $description = 'Verify the AI forecasting lifecycle end to end on SQLite (migrate, seed, train, simulate, forecast, apply, isolate)';

    private int $failures = 0;

    private int $checks = 0;

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->error('The end-to-end check runs on SQLite only (DB_CONNECTION=sqlite): it rebuilds the database.');

            return self::FAILURE;
        }

        $this->step('Migrate and seed');
        $this->seed();

        $tenants = ['CH' => AiForecastingDemoSeeder::demoId('CH', 'tenant'), 'IT' => AiForecastingDemoSeeder::demoId('IT', 'tenant')];

        $this->step('Step 1: payment model training and recency weighting');
        $this->verifyTraining($tenants);

        $this->step('Step 2: 13-week and 12-month liquidity, statutory calendar and what-if');
        $this->verifyLiquidity($tenants);

        $this->step('Step 3: SKU demand, accuracy and one-click apply');
        $this->verifyDemand($tenants);

        $this->step('Step 4: tenant isolation and frontend build');
        $this->verifyIsolation($tenants);
        $this->verifyFrontend();

        $this->newLine();
        $this->line("{$this->checks} checks, {$this->failures} failed.");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function seed(): void
    {
        if ($this->option('skip-migrate')) {
            Artisan::call('db:seed', ['--class' => AiForecastingDemoSeeder::class, '--force' => true]);
        } else {
            Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => AiForecastingDemoSeeder::class, '--force' => true]);
        }

        $this->check('demo tenants seeded', DB::table('tenants')->whereIn('id', [AiForecastingDemoSeeder::demoId('CH', 'tenant'), AiForecastingDemoSeeder::demoId('IT', 'tenant')])->count() === 2);
    }

    /**
     * @param  array<string, string>  $tenants
     */
    private function verifyTraining(array $tenants): void
    {
        $handler = app(TrainCustomerPaymentDelayModelHandler::class);

        foreach ($tenants as $key => $tenantId) {
            $result = $handler->handle(new TrainCustomerPaymentDelayModelCommand($tenantId));
            $this->check("{$key}: four customer models trained and 12 open invoices dated", $result['models_trained'] === 4 && $result['invoices_updated'] === 12, json_encode($result) ?: '');

            $models = PaymentBehaviorModel::withoutGlobalScopes()->where('tenant_id', $tenantId)->get()->keyBy('customer_id');
            $tiers = ['low' => PaymentRiskTier::Low, 'moderate' => PaymentRiskTier::Moderate, 'high' => PaymentRiskTier::High, 'critical' => PaymentRiskTier::Critical];

            foreach ($tiers as $name => $tier) {
                $customerId = AiForecastingDemoSeeder::demoId($key, "customer-{$name}");
                $this->check("{$key}: {$name} customer is tier {$tier->value}", ($models[$customerId]->payment_risk_tier ?? null) === $tier, 'offset '.($models[$customerId]->predicted_collection_date_offset_days ?? '?'));
            }

            $critical = $models[AiForecastingDemoSeeder::demoId($key, 'customer-critical')];
            $flat = $this->flatMeanDelay($tenantId, AiForecastingDemoSeeder::demoId($key, 'customer-critical'));
            $this->check("{$key}: critical offset {$critical->predicted_collection_date_offset_days}d > 40 and above the flat 24-month mean ".round($flat, 1).'d', $critical->predicted_collection_date_offset_days > 40 && $critical->predicted_collection_date_offset_days > $flat);

            $expected = InvoiceExpectedCollection::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('customer_id', $critical->customer_id)->get();
            $dated = $expected->every(fn (InvoiceExpectedCollection $row): bool => $row->expected_collection_date->equalTo($row->due_date->addDays($critical->predicted_collection_date_offset_days)));
            $this->check("{$key}: open invoices of the critical customer expected at due date + offset", $expected->count() === 2 && $dated);
        }
    }

    private function flatMeanDelay(string $tenantId, string $customerId): float
    {
        $delays = [];

        foreach (DB::table('invoices as i')->join('payments as p', 'p.invoice_id', '=', 'i.id')->where('i.tenant_id', $tenantId)->where('i.contact_id', $customerId)->get(['i.due_date', 'p.paid_at']) as $row) {
            $delays[] = CarbonImmutable::parse($row->due_date)->diffInDays(CarbonImmutable::parse($row->paid_at), false);
        }

        return array_sum($delays) / max(1, count($delays));
    }

    /**
     * @param  array<string, string>  $tenants
     */
    private function verifyLiquidity(array $tenants): void
    {
        $handler = app(RunAiLiquiditySimulationHandler::class);
        $today = CarbonImmutable::today();

        foreach ($tenants as $key => $tenantId) {
            foreach ([91, 365] as $days) {
                $run = $handler->handle(new RunAiLiquiditySimulationCommand($tenantId, $days));
                $buckets = $run->daily_projections_json;
                $ordered = collect($buckets)->every(fn (array $bucket): bool => $bucket['balance']['p10'] <= $bucket['balance']['p50'] && $bucket['balance']['p50'] <= $bucket['balance']['p90']);
                $this->check("{$key} {$days}d: {$days} daily buckets with p10 <= p50 <= p90", count($buckets) === $days && $ordered);

                $events = collect($buckets)->flatMap(fn (array $bucket): array => $bucket['statutory_outflows']);
                $this->verifyStatutory($key, $days, $events->all(), $today);
            }

            $baseline = $handler->handle(new RunAiLiquiditySimulationCommand($tenantId, 91, null, false))->toDomain()->cashTrough();
            $delayed = $handler->handle(new RunAiLiquiditySimulationCommand($tenantId, 91, new WhatIfScenarioDto(globalCustomerDelayShiftDays: 15), false))->toDomain()->cashTrough();
            $this->check("{$key}: what-if +15 days lowers the p50 cash trough ({$baseline['balance']} -> {$delayed['balance']})", $delayed['balance'] < $baseline['balance']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function verifyStatutory(string $key, int $days, array $events, CarbonImmutable $today): void
    {
        $from = $today->addDay();
        $to = $today->addDays($days);
        $has = fn (string $code, string $nominal): bool => collect($events)->contains(fn (array $event): bool => $event['code'] === $code && $event['nominal_date'] === $nominal);
        $inside = fn (CarbonImmutable $date): bool => $date->between($from->addDays(3), $to->subDays(3));
        $expected = [];

        for ($year = (int) $from->format('Y'); $year <= (int) $to->format('Y'); $year++) {
            if ($key === 'CH') {
                $expected[] = ['MWST_Q1', "{$year}-05-31"];
                $expected[] = ['MWST_Q2', "{$year}-08-31"];
                $expected[] = ['MWST_Q3', "{$year}-11-30"];
                $expected[] = ['MWST_Q4', CarbonImmutable::create($year, 2, 1)->endOfMonth()->toDateString()];
                $expected[] = ['SALARY_13TH', "{$year}-11-25"];

                continue;
            }

            foreach ([['LIPE_Q1', '05-16'], ['LIPE_Q2', '08-20'], ['LIPE_Q3', '11-16'], ['LIPE_Q4', '03-16'], ['IVA_ADVANCE', '12-27'], ['TAX_ADVANCE_JUN', '06-30'], ['TAX_ADVANCE_NOV', '11-30'], ['SALARY_13TH', '12-20'], ['SALARY_14TH', '06-20']] as [$code, $monthDay]) {
                $expected[] = [$code, "{$year}-{$monthDay}"];
            }

            for ($month = 1; $month <= 12; $month++) {
                $expected[] = ['F24', sprintf('%04d-%02d-16', $year, $month)];
            }
        }

        $missing = [];
        $checked = 0;

        foreach ($expected as [$code, $date]) {
            if ($inside(CarbonImmutable::parse($date))) {
                $checked++;
                $has($code, $date) || $missing[] = "{$code} {$date}";
            }
        }

        $this->check("{$key} {$days}d: {$checked} statutory events on their legal dates", $checked > 0 && $missing === [], $missing === [] ? '' : 'missing '.implode(', ', $missing));
    }

    /**
     * @param  array<string, string>  $tenants
     */
    private function verifyDemand(array $tenants): void
    {
        foreach ($tenants as $key => $tenantId) {
            $forecasts = app(GenerateSkuDemandForecastHandler::class)->handle(new GenerateSkuDemandForecastCommand($tenantId));
            $this->check("{$key}: forecasts generated for the three SKUs", count($forecasts) === 3);

            $this->verifyFlatSku($key, $tenantId, collect($forecasts)->firstWhere('product_id', AiForecastingDemoSeeder::demoId($key, 'product-SKU-002')));
            $this->verifySeasonalSku($key, collect($forecasts)->firstWhere('product_id', AiForecastingDemoSeeder::demoId($key, 'product-SKU-001')));

            $accuracy = app(GetSkuForecastVsActualAccuracyHandler::class)->handle(new GetSkuForecastVsActualAccuracyQuery($tenantId));
            $mape = $accuracy['global']['mape'];
            $wape = $accuracy['global']['wape'];
            $this->check("{$key}: accuracy over closed periods MAPE {$mape}% and WAPE {$wape}% within [0, 100)", $accuracy['global']['sample_size'] >= 3 && $mape >= 0 && $mape < 100 && $wape >= 0 && $wape < 100);

            $this->verifyApply($key, $tenantId, AiForecastingDemoSeeder::demoId($key, 'product-SKU-001'));
        }
    }

    /**
     * SKU-002: flat demand, so the forecast must equal the analytical values computed from the raw sales and purchases.
     */
    private function verifyFlatSku(string $key, string $tenantId, ?SkuDemandForecastRecord $forecast): void
    {
        $productId = AiForecastingDemoSeeder::demoId($key, 'product-SKU-002');
        $sales = DB::table('sales_order_items as i')->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')->where('i.tenant_id', $tenantId)->where('i.product_id', $productId)
            ->where('o.date', '<', CarbonImmutable::today()->startOfMonth()->toDateString())->pluck('i.quantity')->map(fn ($quantity): float => (float) $quantity)->all();
        $mean = array_sum($sales) / count($sales);
        $sigma = sqrt(array_sum(array_map(fn (float $quantity): float => ($quantity - $mean) ** 2, $sales)) / (count($sales) - 1));

        $leadTimes = [];

        foreach (DB::table('goods_receipt_items as gri')->join('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')->join('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->where('gri.tenant_id', $tenantId)->where('gri.product_id', $productId)->get(['po.date as order_date', 'gr.date as receipt_date']) as $row) {
            $leadTimes[] = (float) CarbonImmutable::parse($row->order_date)->diffInDays(CarbonImmutable::parse($row->receipt_date));
        }

        $leadTime = array_sum($leadTimes) / count($leadTimes);
        $leadSigma = sqrt(array_sum(array_map(fn (float $days): float => ($days - $leadTime) ** 2, $leadTimes)) / (count($leadTimes) - 1));
        $perDay = $mean / SkuDemandForecast::DaysPerMonth;
        $safetyStock = SkuDemandForecast::safetyStock(1.65, $leadTime, $sigma / sqrt(SkuDemandForecast::DaysPerMonth), $perDay, $leadSigma);
        $reorderPoint = SkuDemandForecast::reorderPoint($perDay, $leadTime, $safetyStock);

        $this->check("{$key} SKU-002: LT {$leadTime}d (sigma ".round($leadSigma, 2).'d) from the purchase history', abs($leadTime - 21.0) < 0.01 && abs($leadSigma - 9.0) < 0.01);
        $this->check("{$key} SKU-002: safety stock ".round((float) $forecast?->recommended_safety_stock, 2).' vs analytical '.round($safetyStock, 2).' within 1%', $forecast !== null && abs((float) $forecast->recommended_safety_stock - $safetyStock) <= 0.01 * $safetyStock);
        $this->check("{$key} SKU-002: reorder point ".round((float) $forecast?->recommended_reorder_point, 2).' vs analytical '.round($reorderPoint, 2).' within 1%', $forecast !== null && abs((float) $forecast->recommended_reorder_point - $reorderPoint) <= 0.01 * $reorderPoint);
    }

    /**
     * SKU-001: seasonal demand, so the Holt-Winters model must win, and ROP = D * LT + SS must hold for the lead time of the data.
     */
    private function verifySeasonalSku(string $key, ?SkuDemandForecastRecord $forecast): void
    {
        $perDay = (float) $forecast?->predicted_demand_qty / SkuDemandForecast::DaysPerMonth;
        $floor = 1.65 * $perDay * 3.5;
        $this->check("{$key} SKU-001: Holt-Winters model, LT 14d (sigma 3.5d) from the purchase history", $forecast !== null && $forecast->model === 'holt_winters' && abs((float) $forecast->supplier_lead_time_avg_days - 14.0) < 0.01 && abs((float) $forecast->supplier_lead_time_std_dev_days - 3.5) < 0.01);
        $this->check("{$key} SKU-001: reorder point = demand x lead time + safety stock within 1%, safety stock covers the lead time variance", $forecast !== null
            && abs((float) $forecast->recommended_reorder_point - ($perDay * 14.0 + (float) $forecast->recommended_safety_stock)) <= 0.01 * (float) $forecast->recommended_reorder_point
            && (float) $forecast->recommended_safety_stock >= $floor * 0.99);
    }

    private function verifyApply(string $key, string $tenantId, string $productId): void
    {
        $results = app(ApplyAiRecommendedReorderRulesHandler::class)->handle(new ApplyAiRecommendedReorderRulesCommand($tenantId, [], [$productId]));
        $forecast = SkuDemandForecastRecord::withoutGlobalScopes()->findOrFail($results[0]['forecast_id']);
        $rule = DB::table('stock_reorder_rules')->where('tenant_id', $tenantId)->where('stock_item_id', AiForecastingDemoSeeder::demoId($key, 'item-SKU-001'))->first();
        $planning = DB::table('mfg_items')->where('tenant_id', $tenantId)->where('item_code', 'SKU-001')->first();
        $audited = DB::table('enterprise_audit_ledger')->where('tenant_id', $tenantId)->where('resource_id', $forecast->id)->where('action', 'FORECASTING.REORDER_RULES_APPLIED')->exists();

        $this->check("{$key} SKU-001: WMS reorder_point and safety stock updated", $rule !== null && abs((float) $rule->reorder_point - (float) $forecast->recommended_reorder_point) < 0.001 && abs((float) $rule->min_stock_level - (float) $forecast->recommended_safety_stock) < 0.001);
        $this->check("{$key} SKU-001: MPS/MRP safety stock updated", $planning !== null && abs((float) $planning->safety_stock - (float) $forecast->recommended_safety_stock) < 0.001);
        $this->check("{$key} SKU-001: immutable audit entry and applied_to_wms_at", $audited && $forecast->applied_to_wms_at !== null);
    }

    /**
     * @param  array<string, string>  $tenants
     */
    private function verifyIsolation(array $tenants): void
    {
        $chUser = User::query()->where('email', 'forecast.ch@mod094.test')->firstOrFail();
        $itRun = LiquidityForecastRun::withoutGlobalScopes()->where('tenant_id', $tenants['IT'])->first();
        $itForecast = SkuDemandForecastRecord::withoutGlobalScopes()->where('tenant_id', $tenants['IT'])->first();

        $own = $this->http('GET', '/api/v1/forecasting/liquidity/confidence-bands', $chUser, $tenants['CH']);
        $foreignRun = $this->http('GET', '/api/v1/forecasting/liquidity/confidence-bands?run_id='.$itRun?->id, $chUser, $tenants['CH']);
        $foreignApply = $this->http('POST', '/api/v1/forecasting/demand/apply-reorder-rules', $chUser, $tenants['CH'], ['forecast_ids' => [$itForecast?->id]]);
        $foreignTenant = $this->http('GET', '/api/v1/forecasting/liquidity/confidence-bands', $chUser, $tenants['IT']);

        $this->check("CH user reads its own run over HTTP (status {$own})", $own === 200);
        $this->check("CH user asking for an IT run id gets 404 (status {$foreignRun})", in_array($foreignRun, [403, 404], true));
        $this->check("CH user applying an IT forecast gets 404 (status {$foreignApply})", in_array($foreignApply, [403, 404], true));
        $this->check("CH user using the IT tenant header gets 403 (status {$foreignTenant})", in_array($foreignTenant, [403, 404], true));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function http(string $method, string $uri, User $user, string $tenantId, array $body = []): int
    {
        app('auth')->forgetGuards();
        app(TenantContext::class)->clear();
        Sanctum::actingAs($user);

        $request = Request::create('https://localhost'.$uri, $method, $body, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_TENANT_ID' => $tenantId, 'HTTP_X_USER_ID' => (string) $user->id, 'CONTENT_TYPE' => 'application/json'], $body === [] ? null : json_encode($body));
        $response = app(Kernel::class)->handle($request);

        return $response->getStatusCode();
    }

    private function verifyFrontend(): void
    {
        $path = (string) $this->option('frontend');

        if ($path === '') {
            $this->line('  (frontend build skipped: pass --frontend=<path of web-frontend>)');

            return;
        }

        // web-frontend enforces pnpm (only-allow); the build script behind `npm run build` is the same one. On Windows the
        // package managers are .cmd shims that a process cannot start by their bare name.
        $manager = is_file(rtrim($path, '/\\').'/pnpm-lock.yaml') ? 'pnpm' : 'npm';
        $binary = PHP_OS_FAMILY === 'Windows' ? "{$manager}.cmd" : $manager;
        $process = new Process([$binary, 'run', 'build'], $path, null, null, 1800);
        $process->run();
        $this->check("{$manager} run build in web-frontend", $process->isSuccessful(), $process->isSuccessful() ? '' : substr($process->getErrorOutput().$process->getOutput(), -800));
    }

    private function step(string $title): void
    {
        $this->newLine();
        $this->info($title);
    }

    private function check(string $label, bool $passed, string $detail = ''): void
    {
        $this->checks++;
        $passed || $this->failures++;
        $this->line(($passed ? '  PASS ' : '  FAIL ').$label.($detail !== '' && ! $passed ? " [{$detail}]" : ''));
    }
}
