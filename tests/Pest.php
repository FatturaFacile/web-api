<?php

declare(strict_types=1);
use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Models\SalesOrder;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you build a more flexible test suite.
|
*/

/**
 * An open fiscal calendar, so that documents of that year can reach the ledger.
 */
function fiscalCalendar(Tenant $tenant, int $year = 2027): void
{
    $fiscalYear = FiscalYear::create([
        'tenant_id' => (string) $tenant->id,
        'year_code' => "FY{$year}",
        'start_date' => "{$year}-01-01",
        'end_date' => "{$year}-12-31",
        'status' => 'open',
    ]);

    foreach (range(1, 12) as $month) {
        $first = CarbonImmutable::create($year, $month, 1);

        FiscalPeriod::create([
            'tenant_id' => (string) $tenant->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_number' => $month,
            'period_name' => $first->format('F Y'),
            'start_date' => $first->toDateString(),
            'end_date' => $first->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);
    }
}

/**
 * Headers of a request that comes from the first-party SPA (https origin listed in the Sanctum stateful domains): the
 * only kind of request that can open the cookie session.
 *
 * @return array<string, string>
 */
function spaHeaders(): array
{
    $origin = rtrim((string) config('app.frontend_url'), '/');

    return ['Origin' => $origin, 'Referer' => $origin.'/'];
}

/**
 * Logistics test fixtures (tenant + owner user + catalog + warehouses).
 *
 * @return array{tenant: Tenant, user: User}
 */
function tfrTenant(string $name = 'Logistics Tenant', string $role = 'owner', ?array $permissions = null): array
{
    $tenant = Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => $name,
        'legal_name' => $name.' Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'name' => $name.' User',
        'email' => Str::slug($name).'-'.Str::random(5).'@logistics.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'role' => $role,
        'permissions' => $permissions === null ? null : json_encode($permissions),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['tenant' => $tenant, 'user' => $user];
}

function tfrProduct(string $tenantId, string $sku, float $cost = 10.0, float $sale = 25.0): Product
{
    return Product::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'sku' => $sku,
        'name' => 'Product '.$sku,
        'cost_price' => $cost,
        'sale_price' => $sale,
        'tax_rate' => 22.0,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function tfrWarehouse(string $tenantId, string $code, array $attributes = []): Warehouse
{
    return Warehouse::create(array_merge([
        'tenant_id' => $tenantId,
        'code' => $code,
        'name' => 'Warehouse '.$code,
        'warehouse_type' => 'standard',
        'legal_owner_type' => 'company',
    ], $attributes));
}

function tfrCustomer(string $tenantId, string $name = 'Cliente SpA'): Contact
{
    return Contact::create(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'company_name' => $name, 'type' => 'client', 'country' => 'IT']);
}

function tfrUserWithRole(string $tenantId, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'name' => ucfirst($role).' user',
        'email' => $role.'-'.Str::random(6).'@logistics.test',
        'password' => bcrypt('password123'),
    ]);
    DB::table('tenant_user')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => $user->id,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function tfrOrder(string $tenantId, string $customerId, string $productId, float $qty, string $number): SalesOrder
{
    return app(SalesOrderServiceInterface::class)->createOrder([
        'tenant_id' => $tenantId, 'contact_id' => $customerId, 'order_number' => $number,
    ], [['product_id' => $productId, 'description' => 'Gateway', 'quantity' => $qty, 'unit_price' => 50.0, 'tax_rate' => 22.0]]);
}
