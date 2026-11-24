<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\SupplierLedger;

uses(RefreshDatabase::class);

/**
 * HTTP behaviour of the customer and supplier subledgers and of the reconciliation runs of the general ledger: what
 * the lists show (own tenant only, filters, order, page size), what a run computes (GL control balance against the open
 * items of the subledger, status balanced or discrepancy, variance) and who may run it.
 *
 * @return array<string, mixed>
 */
function glApiWorld(string $role = 'owner'): array
{
    $tenant = Tenant::create(['name' => 'GL '.Str::random(4), 'legal_name' => 'GL SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $user = User::create(['name' => 'Acc', 'email' => Str::random(10).'@example.test', 'password' => bcrypt(Str::password(24)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    app(TenantContext::class)->setTenant($tenant);

    $year = FiscalYear::create(['tenant_id' => (string) $tenant->id, 'year_code' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
    $period = FiscalPeriod::create(['tenant_id' => (string) $tenant->id, 'fiscal_year_id' => $year->id, 'period_number' => 1, 'period_name' => 'January 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']);

    $account = static fn (string $code, string $class, string $normal, ?string $reconciliation = null): ChartOfAccount => ChartOfAccount::create([
        'tenant_id' => (string) $tenant->id, 'account_code' => $code, 'name' => "Account {$code}", 'account_class' => $class, 'account_type' => $class,
        'normal_balance' => $normal, 'reconciliation_type' => $reconciliation, 'is_reconciliation_account' => $reconciliation !== null, 'is_active' => true,
    ]);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'period' => $period,
        'receivable' => $account('1100', 'asset', 'debit', 'customer'),
        'payable' => $account('2000', 'liability', 'credit', 'supplier'),
        'revenue' => $account('4000', 'revenue', 'credit'),
        'cost' => $account('6000', 'expense', 'debit'),
    ];
}

/**
 * Posts an entry whose partner (customer on the receivable debit, supplier on the payable credit) opens a subledger item.
 *
 * @param  array<string, mixed>  $world
 */
function glPostWithPartner(array $world, string $debit, string $credit, string $amount, string $partnerType, string $partnerId, string $number): void
{
    app(TenantContext::class)->setTenant($world['tenant']);
    $partner = ['partner_type' => $partnerType, 'partner_id' => $partnerId];

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => (string) $world['tenant']->id,
        'fiscal_period_id' => (string) $world['period']->id,
        'entry_number' => $number,
        'description' => $number,
        'lines' => [
            ['account_id' => (string) $world[$debit]->id, 'debit_amount' => (float) $amount, 'credit_amount' => 0.0, ...($partnerType === 'customer' ? $partner : [])],
            ['account_id' => (string) $world[$credit]->id, 'debit_amount' => 0.0, 'credit_amount' => (float) $amount, ...($partnerType === 'supplier' ? $partner : [])],
        ],
    ]);
}

/**
 * @param  array<string, mixed>  $world
 */
function subledgerApi(array $world): mixed
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($world['user']);

    return test()->withHeader('X-Tenant-Id', (string) $world['tenant']->id);
}

test('the customer subledger lists the open items of the tenant by due date, with filters', function (): void {
    $world = glApiWorld();
    $alfa = (string) Str::uuid7();
    $beta = (string) Str::uuid7();
    glPostWithPartner($world, 'receivable', 'revenue', '300', 'customer', $alfa, 'AR-1');
    glPostWithPartner($world, 'receivable', 'revenue', '200', 'customer', $beta, 'AR-2');

    expect(CustomerLedger::query()->count())->toBe(2);

    CustomerLedger::query()->where('customer_id', $alfa)->update(['due_date' => '2026-03-31', 'status' => 'open']);
    CustomerLedger::query()->where('customer_id', $beta)->update(['due_date' => '2026-02-15', 'status' => 'paid']);

    $all = subledgerApi($world)->getJson('/api/v1/general-ledger/subledgers/customers')->assertOk();
    expect(array_column($all->json('data.data'), 'customer_id'))->toBe([$beta, $alfa]);

    $open = subledgerApi($world)->getJson('/api/v1/general-ledger/subledgers/customers?status=open')->assertOk();
    expect(array_column($open->json('data.data'), 'customer_id'))->toBe([$alfa]);

    $byCustomer = subledgerApi($world)->getJson("/api/v1/general-ledger/subledgers/customers?customer_id={$beta}")->assertOk();
    expect(array_column($byCustomer->json('data.data'), 'customer_id'))->toBe([$beta]);

    $paged = subledgerApi($world)->getJson('/api/v1/general-ledger/subledgers/customers?per_page=1')->assertOk();
    expect($paged->json('data.data'))->toHaveCount(1)->and($paged->json('data.total'))->toBe(2);

    // A page size of zero or beyond the cap is brought back into range instead of failing or loading the whole table.
    $zero = subledgerApi($world)->getJson('/api/v1/general-ledger/subledgers/customers?per_page=0')->assertOk();
    $huge = subledgerApi($world)->getJson('/api/v1/general-ledger/subledgers/customers?per_page=100000')->assertOk();
    expect($zero->json('data.per_page'))->toBe(1)->and($huge->json('data.per_page'))->toBe(200);
});

test('the supplier subledger lists the items of the tenant only', function (): void {
    $world = glApiWorld();
    $other = glApiWorld();
    $supplier = (string) Str::uuid7();
    glPostWithPartner($world, 'cost', 'payable', '450', 'supplier', $supplier, 'AP-1');
    glPostWithPartner($other, 'cost', 'payable', '999', 'supplier', (string) Str::uuid7(), 'AP-X');

    expect(SupplierLedger::withoutGlobalScopes()->count())->toBe(2);

    $response = subledgerApi($world)->getJson('/api/v1/general-ledger/subledgers/suppliers')->assertOk();
    expect(array_column($response->json('data.data'), 'supplier_id'))->toBe([$supplier]);

    $filtered = subledgerApi($world)->getJson("/api/v1/general-ledger/subledgers/suppliers?supplier_id={$supplier}&status=cleared")->assertOk();
    expect($filtered->json('data.data'))->toBe([]);
});

test('a reconciliation run balances the control account against the open items of the subledger', function (): void {
    $world = glApiWorld();
    glPostWithPartner($world, 'receivable', 'revenue', '5000', 'customer', (string) Str::uuid7(), 'AR-REC');

    $response = subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', [
        'reconciliation_type' => 'subledger_ar',
        'fiscal_period_id' => (string) $world['period']->id,
    ])->assertCreated()->assertJsonPath('message', 'Reconciliation completed');

    expect($response->json('data.status'))->toBe('balanced')
        ->and($response->json('data.gl_balance'))->toEqual(5000)
        ->and($response->json('data.subledger_balance'))->toEqual(5000)
        ->and($response->json('data.variance_amount'))->toEqual(0)
        ->and($response->json('data.account_id'))->toBe((string) $world['receivable']->id);
});

test('a reconciliation run reports the discrepancy when the open items do not add up to the control account', function (): void {
    $world = glApiWorld();
    glPostWithPartner($world, 'receivable', 'revenue', '5000', 'customer', (string) Str::uuid7(), 'AR-DIFF');
    CustomerLedger::query()->update(['open_amount' => 3800, 'status' => 'partially_paid']);

    $response = subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', [
        'reconciliation_type' => 'subledger_ar',
        'fiscal_period_id' => (string) $world['period']->id,
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('discrepancy')
        ->and($response->json('data.gl_balance'))->toEqual(5000)
        ->and($response->json('data.subledger_balance'))->toEqual(3800)
        ->and($response->json('data.variance_amount'))->toEqual(1200);
});

test('the payables run and the goods receipt run use their own accounts', function (): void {
    $world = glApiWorld();
    glPostWithPartner($world, 'cost', 'payable', '700', 'supplier', (string) Str::uuid7(), 'AP-REC');

    $payables = subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'subledger_ap', 'fiscal_period_id' => (string) $world['period']->id])->assertCreated();
    $grIr = subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'gr_ir', 'fiscal_period_id' => (string) $world['period']->id])->assertCreated();

    expect($payables->json('data.status'))->toBe('balanced')
        ->and($payables->json('data.gl_balance'))->toEqual(700)
        ->and($payables->json('data.account_id'))->toBe((string) $world['payable']->id)
        ->and($grIr->json('data.reconciliation_type'))->toBe('gr_ir')
        ->and($grIr->json('data.status'))->toBe('balanced');
});

test('runs are listed and shown for the own tenant only', function (): void {
    $world = glApiWorld();
    $other = glApiWorld();
    glPostWithPartner($world, 'receivable', 'revenue', '100', 'customer', (string) Str::uuid7(), 'AR-A');
    glPostWithPartner($other, 'receivable', 'revenue', '900', 'customer', (string) Str::uuid7(), 'AR-B');

    $own = subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'subledger_ar', 'fiscal_period_id' => (string) $world['period']->id])->assertCreated();
    $foreign = subledgerApi($other)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'subledger_ar', 'fiscal_period_id' => (string) $other['period']->id])->assertCreated();

    $list = subledgerApi($world)->getJson('/api/v1/general-ledger/reconciliations')->assertOk();
    $rows = $list->json('data.data') ?? $list->json('data');
    expect(array_column($rows, 'id'))->toBe([$own->json('data.id')]);

    subledgerApi($world)->getJson('/api/v1/general-ledger/reconciliations/'.$own->json('data.id'))->assertOk()->assertJsonPath('data.gl_balance', 100);
    subledgerApi($world)->getJson('/api/v1/general-ledger/reconciliations/'.$foreign->json('data.id'))->assertNotFound();
});

test('a run needs a known type and a fiscal period of the tenant', function (): void {
    $world = glApiWorld();
    $other = glApiWorld();

    subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'cash', 'fiscal_period_id' => (string) $world['period']->id])->assertUnprocessable()->assertJsonValidationErrors('reconciliation_type');
    subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'subledger_ar', 'fiscal_period_id' => (string) $other['period']->id])->assertUnprocessable()->assertJsonValidationErrors('fiscal_period_id');
    subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', [])->assertUnprocessable();

    expect(DB::table('reconciliation_runs')->count())->toBe(0);
});

test('a role that cannot post to the ledger cannot run a reconciliation', function (): void {
    $world = glApiWorld('member');

    subledgerApi($world)->postJson('/api/v1/general-ledger/reconciliations', ['reconciliation_type' => 'subledger_ar', 'fiscal_period_id' => (string) $world['period']->id])->assertForbidden();
});
