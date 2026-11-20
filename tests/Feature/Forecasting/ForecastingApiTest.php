<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\AiForecastingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Forecasting\Infrastructure\Models\LiquidityForecastRun;
use Modules\Forecasting\Infrastructure\Models\SkuDemandForecastRecord;

uses(RefreshDatabase::class);

const FC = '/api/v1/forecasting';

function fcTenant(string $key): string
{
    return AiForecastingDemoSeeder::demoId($key, 'tenant');
}

/**
 * Acts as a new member of the demo tenant with the role and the granular permissions.
 *
 * @param  list<string>  $permissions
 */
function fcActAs(string $key, string $role = 'owner', array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach(fcTenant($key), ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', fcTenant($key));

    return $user;
}

beforeEach(function (): void {
    $this->seed(AiForecastingDemoSeeder::class);
});

test('the demo seeder is idempotent', function (): void {
    $counts = fn (): array => [DB::table('invoices')->count(), DB::table('payments')->count(), DB::table('sales_order_items')->count(), DB::table('goods_receipt_items')->count(), DB::table('contacts')->count(), DB::table('tenants')->whereIn('id', [fcTenant('CH'), fcTenant('IT')])->count()];
    $before = $counts();

    $this->seed(AiForecastingDemoSeeder::class);

    expect($counts())->toBe($before)
        ->and($before[5])->toBe(2)
        // 4 profiles: 30 + 25 + 20 + 15 settled and 3 + 4 + 3 + 2 open invoices per tenant.
        ->and(DB::table('invoices')->where('tenant_id', fcTenant('CH'))->count())->toBe(102);
});

test('the owner trains the payment models and sees the risk table', function (): void {
    fcActAs('IT');

    $this->postJson(FC.'/payment-models/train')->assertOk()->assertJsonPath('data.models_trained', 4)->assertJsonPath('data.invoices_updated', 12);

    $rows = $this->getJson(FC.'/payment-models')->assertOk()->json('data');

    expect($rows)->toHaveCount(4)
        ->and($rows[0]['payment_risk_tier'])->toBe('critical')
        ->and($rows[0]['predicted_collection_date_offset_days'])->toBeGreaterThan(40)
        ->and($rows[0]['open_invoices'])->toHaveCount(2)
        ->and($rows[3]['payment_risk_tier'])->toBe('low')
        ->and($rows[0]['customer_name'])->toBe('Sicilia Costruzioni Srl');
});

test('training accepts a half-life and rejects an invalid one', function (): void {
    fcActAs('CH');

    $this->postJson(FC.'/payment-models/train', ['half_life_days' => 180])->assertOk();
    $this->postJson(FC.'/payment-models/train', ['half_life_days' => 1])->assertUnprocessable()->assertJsonValidationErrors('half_life_days');
});

test('simulating and reading the confidence bands', function (): void {
    fcActAs('IT');
    $this->postJson(FC.'/payment-models/train')->assertOk();

    $run = $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91])->assertCreated()->json('data');

    expect($run['daily_projections'])->toHaveCount(91)
        ->and($run['currency'])->toBe('EUR')
        ->and($run['starting_cash_balance'])->toEqual(140000)
        ->and($run['key_drivers'])->toHaveKeys(['overdue_invoices', 'statutory_liabilities', 'customer_delays'])
        ->and($run['what_if_parameters'])->toBeNull();

    $bands = $this->getJson(FC.'/liquidity/confidence-bands')->assertOk()->json('data');

    expect($bands['run_id'])->toBe($run['id'])
        ->and($bands['dates'])->toHaveCount(91)
        ->and($bands['p10'])->toHaveCount(91)
        ->and($bands['p50'])->toHaveCount(91)
        ->and($bands['p90'])->toHaveCount(91)
        ->and($bands['statutoryEvents'])->not->toBeEmpty()
        ->and($bands['trough'])->toHaveKeys(['date', 'balance']);

    foreach ($bands['p50'] as $index => $p50) {
        expect($bands['p10'][$index])->toBeLessThanOrEqual($p50)->and($p50)->toBeLessThanOrEqual($bands['p90'][$index]);
    }
});

test('a what-if is stored with the run and a preview is not stored', function (): void {
    fcActAs('CH');

    $run = $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91, 'what_if' => ['global_customer_delay_shift_days' => 15, 'supplier_payment_extension_days' => 10]])->assertCreated()->json('data');

    expect($run['what_if_parameters']['global_customer_delay_shift_days'])->toBe(15)
        ->and(LiquidityForecastRun::withoutGlobalScopes()->where('tenant_id', fcTenant('CH'))->count())->toBe(1);

    $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 365, 'preview' => true])->assertCreated();

    expect(LiquidityForecastRun::withoutGlobalScopes()->where('tenant_id', fcTenant('CH'))->count())->toBe(1);
});

test('the simulation validates its input', function (): void {
    fcActAs('CH');

    $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 100])->assertUnprocessable()->assertJsonValidationErrors('horizon_days');
    $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91, 'what_if' => ['global_customer_delay_shift_days' => 500]])->assertUnprocessable();
    $this->getJson(FC.'/liquidity/confidence-bands')->assertNotFound();
});

test('generating forecasts, reading the grid and the accuracy', function (): void {
    fcActAs('CH');

    $forecasts = $this->postJson(FC.'/demand/generate')->assertCreated()->json('data');

    expect($forecasts)->toHaveCount(3);

    $grid = $this->getJson(FC.'/demand/forecasts')->assertOk()->json('data');
    $sku1 = collect($grid)->firstWhere('sku', 'SKU-001');

    expect($grid)->toHaveCount(3)
        ->and($sku1['current_reorder_point'])->toEqual(60)
        ->and($sku1['current_safety_stock'])->toEqual(20)
        ->and($sku1['recommended_reorder_point'])->toBeGreaterThan(0.0)
        ->and($sku1['current_stock'])->toEqual(300)
        ->and($sku1['history'])->toHaveCount(12)
        ->and($sku1['model'])->toBe('holt_winters');

    expect($this->getJson(FC.'/demand/forecasts?search=bearing')->assertOk()->json('data'))->toHaveCount(1);

    $accuracy = $this->getJson(FC.'/demand/accuracy')->assertOk()->json('data');

    expect($accuracy['global']['sample_size'])->toBe(3)
        ->and($accuracy['global']['mape'])->toBeGreaterThanOrEqual(0.0)->toBeLessThan(100.0)
        ->and($accuracy['by_sku'])->toHaveCount(1)
        ->and($accuracy['by_sku'][0]['sku'])->toBe('SKU-003')
        ->and($accuracy['by_warehouse'])->toHaveCount(1);
});

test('applying the recommended reorder rules updates the warehouse and planning data', function (): void {
    fcActAs('IT');
    $forecast = $this->postJson(FC.'/demand/generate', ['product_id' => AiForecastingDemoSeeder::demoId('IT', 'product-SKU-001')])->assertCreated()->json('data.0');

    $applied = $this->postJson(FC.'/demand/apply-reorder-rules', ['forecast_ids' => [$forecast['id']]])->assertOk()->json('data.0');

    expect($applied['reorder_point'])->toBe($forecast['recommended_reorder_point'])
        ->and($applied['planning_items_updated'])->toBe(1);

    $rule = DB::table('stock_reorder_rules')->where('tenant_id', fcTenant('IT'))->first();

    expect((float) $rule->reorder_point)->toBe($forecast['recommended_reorder_point'])
        ->and((float) $rule->min_stock_level)->toBe($forecast['recommended_safety_stock'])
        ->and(SkuDemandForecastRecord::withoutGlobalScopes()->find($forecast['id'])->applied_to_wms_at)->not->toBeNull()
        ->and(DB::table('enterprise_audit_ledger')->where('resource_id', $forecast['id'])->where('action', 'FORECASTING.REORDER_RULES_APPLIED')->count())->toBe(1);
});

test('applying needs forecasts', function (): void {
    fcActAs('IT');

    $this->postJson(FC.'/demand/apply-reorder-rules', [])->assertUnprocessable();
    $this->postJson(FC.'/demand/apply-reorder-rules', ['product_ids' => [Str::uuid()->toString()]])->assertUnprocessable();
    $this->postJson(FC.'/demand/apply-reorder-rules', ['forecast_ids' => [Str::uuid()->toString()]])->assertNotFound();
});

test('a tenant cannot see or apply the forecasts of another tenant', function (): void {
    fcActAs('IT');
    $itRun = $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91])->json('data.id');
    $itForecast = $this->postJson(FC.'/demand/generate')->json('data.0.id');

    fcActAs('CH');

    $this->getJson(FC.'/liquidity/confidence-bands?run_id='.$itRun)->assertNotFound();
    $this->postJson(FC.'/demand/apply-reorder-rules', ['forecast_ids' => [$itForecast]])->assertNotFound();
    expect(collect($this->getJson(FC.'/demand/forecasts')->json('data'))->pluck('forecast_id'))->not->toContain($itForecast);
});

test('a member of one tenant is refused on another tenant', function (): void {
    fcActAs('CH');

    $this->withHeader('X-Tenant-Id', fcTenant('IT'))->getJson(FC.'/payment-models')->assertForbidden();
});

test('roles and permissions gate the forecasting endpoints', function (): void {
    // A sales person has no access to the module at all.
    fcActAs('CH', 'sales');
    $this->getJson(FC.'/payment-models')->assertForbidden();

    // A read-only accountant reads but cannot train, simulate, generate or apply.
    fcActAs('CH', 'accountant_readonly');
    $this->getJson(FC.'/payment-models')->assertOk();
    $this->postJson(FC.'/payment-models/train')->assertForbidden();
    $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91])->assertForbidden();
    $this->postJson(FC.'/demand/generate')->assertForbidden();
    $this->postJson(FC.'/demand/apply-reorder-rules', ['product_ids' => [Str::uuid()->toString()]])->assertForbidden();

    // The warehouse role reads the demand forecasts and applies them, but does not simulate liquidity.
    fcActAs('CH', 'warehouse');
    $this->getJson(FC.'/demand/forecasts')->assertOk();
    $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91])->assertForbidden();
    $this->postJson(FC.'/demand/apply-reorder-rules', ['product_ids' => [Str::uuid()->toString()]])->assertUnprocessable();
});

test('granular forecasting permissions open the endpoints they name', function (): void {
    fcActAs('CH', 'member', ['forecasting.view']);
    $this->getJson(FC.'/payment-models')->assertOk();
    $this->postJson(FC.'/payment-models/train')->assertForbidden();

    fcActAs('CH', 'member', ['forecasting.simulate']);
    $this->postJson(FC.'/liquidity/simulate', ['horizon_days' => 91])->assertCreated();
    $this->postJson(FC.'/demand/apply-reorder-rules', ['product_ids' => [Str::uuid()->toString()]])->assertForbidden();

    fcActAs('CH', 'member', ['forecasting.apply_wms']);
    $this->postJson(FC.'/demand/apply-reorder-rules', ['product_ids' => [Str::uuid()->toString()]])->assertUnprocessable();
});

test('the forecasting endpoints need authentication', function (): void {
    $this->getJson(FC.'/payment-models')->assertUnauthorized();
});
