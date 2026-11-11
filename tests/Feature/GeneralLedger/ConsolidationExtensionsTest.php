<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;

uses(RefreshDatabase::class);

/**
 * Extensions of the consolidation: proportional method, goodwill and fair value, unrealised intragroup profit,
 * intragroup dividends, indirect holdings, account mappings, fiscal year offset, several investment accounts and the
 * elimination policy. Every scenario is a small group of EUR entities, so each figure can be followed by hand; the
 * period is June 2026 of the parent, with the fiscal year from 1 January.
 */
function extChart(Tenant $tenant): array
{
    app(TenantContext::class)->setTenant($tenant);
    app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2026);
    app(TenantContext::class)->clear();

    $accounts = [];

    foreach ([
        'bank' => ['1000', 'asset', 'debit'], 'receivable' => ['1150', 'asset', 'debit'], 'inventory' => ['1300', 'asset', 'debit'],
        'fixed' => ['1600', 'asset', 'debit'], 'invest' => ['1500', 'asset', 'debit'], 'invest2' => ['1510', 'asset', 'debit'],
        'payable' => ['2150', 'liability', 'credit'], 'capital' => ['3000', 'equity', 'credit'], 'retained' => ['3100', 'equity', 'credit'],
        'sales' => ['4000', 'revenue', 'credit'], 'ic_sales' => ['4100', 'revenue', 'credit'], 'div_income' => ['4200', 'revenue', 'credit'],
        'alt_sales' => ['7000', 'revenue', 'credit'], 'cost' => ['6000', 'expense', 'debit'], 'dep' => ['6200', 'expense', 'debit'],
    ] as $key => [$code, $type, $normal]) {
        $accounts[$key] = ChartOfAccount::factory()->create([
            'tenant_id' => $tenant->id, 'company_id' => null, 'account_code' => $code, 'name' => "Account {$code}",
            'account_type' => $type, 'account_class' => $type, 'normal_balance' => $normal, 'is_active' => true, 'allows_manual_posting' => true,
        ]);
    }

    return $accounts;
}

function extPost(Tenant $tenant, string $date, ChartOfAccount $debit, ChartOfAccount $credit, string $amount): void
{
    app(TenantContext::class)->setTenant($tenant);
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenant->id, 'fiscal_period_id' => $period->id, 'posting_date' => $date, 'description' => 'Extension test posting', 'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => '0'],
            ['account_id' => $credit->id, 'debit_amount' => '0', 'credit_amount' => $amount],
        ],
    ]);
    app(TenantContext::class)->clear();
}

function extActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function extTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name, 'legal_name' => "{$name} SpA", 'default_currency' => 'EUR']);
}

/**
 * @param  array<string, mixed>  $settings
 */
function extGroup(array $settings = []): string
{
    return (string) test()->postJson('/api/v1/general-ledger/consolidation/groups', ['name' => 'Gruppo '.Str::random(6), 'group_currency' => 'EUR', ...$settings])->assertCreated()->json('data.id');
}

function extMember(string $group, Tenant $tenant, int|string $percentage, string $method = 'full', array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/members", ['member_tenant_id' => $tenant->id, 'ownership_percentage' => $percentage, 'consolidation_method' => $method, ...$extra]);
}

function extRelation(string $group, Tenant $a, Tenant $b): string
{
    return (string) test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/relations", ['entity_a_tenant_id' => $a->id, 'entity_b_tenant_id' => $b->id])->assertCreated()->json('data.id');
}

function extMap(string $relation, string $type, string $a, ?string $b): void
{
    test()->postJson("/api/v1/general-ledger/consolidation/relations/{$relation}/mappings", ['mapping_type' => $type, 'account_a_id' => $a, 'account_b_id' => $b])->assertCreated();
}

function extRun(string $group): TestResponse
{
    $period = (string) FiscalPeriod::withoutGlobalScopes()->where('tenant_id', test()->parent->id)->whereDate('start_date', '<=', '2026-06-30')->whereDate('end_date', '>=', '2026-06-30')->firstOrFail()->id;

    return test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/runs", ['fiscal_period_id' => $period]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function extTb(string $runId): array
{
    return collect(test()->getJson("/api/v1/general-ledger/consolidation/runs/{$runId}/trial-balance")->assertOk()->json('data.accounts'))->keyBy('account_code')->all();
}

/**
 * @param  array<string, mixed>  $run
 * @return list<array<string, mixed>>
 */
function extAdjustments(array $run, string $type): array
{
    return array_values(array_filter($run['adjustments'], static fn (array $adjustment): bool => $adjustment['adjustment_type'] === $type));
}

/**
 * @param  array<string, mixed>  $adjustment
 * @return array<string, array<string, string>>
 */
function extLines(array $adjustment): array
{
    return collect($adjustment['lines'])->keyBy('account_code')->all();
}

beforeEach(function (): void {
    $this->parent = extTenant('Capogruppo');
    $this->sub = extTenant('Controllata');
    $this->accountant = User::create(['name' => 'accountant', 'email' => Str::uuid().'@example.com', 'password' => Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9)]);

    foreach ([$this->parent, $this->sub] as $tenant) {
        $this->accountant->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    }

    $this->p = extChart($this->parent);
    $this->s = extChart($this->sub);
    extActAs($this->accountant, $this->parent);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a proportional member is added line by line at its ownership with no minority interests', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '100000');
    extPost($this->parent, '2026-01-05', $this->p['invest'], $this->p['bank'], '40000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '100000');
    extPost($this->sub, '2026-03-01', $this->s['bank'], $this->s['sales'], '20000');
    extPost($this->sub, '2026-03-02', $this->s['cost'], $this->s['bank'], '5000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 40, 'proportional')->assertCreated();
    extMap(extRelation($group, $this->parent, $this->sub), 'investment_equity', $this->p['invest']->id, $this->s['capital']->id);

    $run = extRun($group)->assertCreated()->json('data');
    $tb = extTb($run['id']);

    // J contributes 40 %: bank 115 000 -> 46 000, capital 100 000 -> 40 000, revenue 20 000 -> 8 000, cost 5 000 -> 2 000
    expect($tb['1000']['net'])->toBe('106000.0000')
        ->and($tb['1500']['net'])->toBe('0.0000')
        ->and($tb['3000']['net'])->toBe('-100000.0000')
        ->and($tb['4000']['net'])->toBe('-8000.0000')
        ->and($tb['6000']['net'])->toBe('2000.0000')
        ->and($run['totals']['non_controlling_interests'])->toBe([])
        ->and($run['totals']['trial_balance_is_balanced'])->toBeTrue()
        ->and($run['totals']['balance_sheet_is_balanced'])->toBeTrue()
        ->and(DB::table('gl_consolidation_run_balances')->where('member_tenant_id', $this->sub->id)->where('account_code', '4000')->value('consolidated_share'))->toBe('40.0000');
});

test('goodwill is computed from the acquisition record with fair value, deferred tax and its charges', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-01-05', $this->p['invest'], $this->p['bank'], '100000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-01-03', $this->s['bank'], $this->s['retained'], '20000');
    extPost($this->sub, '2026-03-01', $this->s['bank'], $this->s['sales'], '10000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup(['goodwill_method' => 'straight_line', 'goodwill_amortisation_years' => 10]);
    extMember($group, $this->sub, 80)->assertCreated();
    extMap(extRelation($group, $this->parent, $this->sub), 'investment_equity', $this->p['invest']->id, $this->s['capital']->id);

    // a straight-line method without its years is refused: no period is assumed
    test()->postJson('/api/v1/general-ledger/consolidation/groups', ['name' => 'Senza anni', 'group_currency' => 'EUR', 'goodwill_method' => 'straight_line'])->assertStatus(422);

    $acquisition = test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", [
        'member_tenant_id' => $this->sub->id, 'acquisition_date' => '2025-04-01', 'net_assets_at_acquisition' => 70000,
        'recognise_deferred_tax' => true, 'deferred_tax_rate' => 25,
        'fair_value_adjustments' => [['account_code' => '1700', 'account_name' => 'Land', 'account_type' => 'asset', 'amount' => 10000]],
    ])->assertCreated()->json('data');

    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", ['member_tenant_id' => $this->sub->id, 'acquisition_date' => '2025-04-01', 'net_assets_at_acquisition' => 1])->assertStatus(409);
    test()->postJson("/api/v1/general-ledger/consolidation/acquisitions/{$acquisition['id']}/impairments", ['fiscal_year' => 2025, 'amount' => 500])->assertCreated();
    test()->postJson("/api/v1/general-ledger/consolidation/acquisitions/{$acquisition['id']}/impairments", ['fiscal_year' => 2026, 'amount' => 1000])->assertCreated();
    test()->postJson("/api/v1/general-ledger/consolidation/acquisitions/{$acquisition['id']}/impairments", ['fiscal_year' => 2026, 'amount' => 5])->assertStatus(409);

    $run = extRun($group)->assertCreated()->json('data');

    // goodwill = 100 000 - 80 % x (70 000 + 10 000 - 2 500 deferred tax) = 38 000; fair value and its tax enter at 80 % only
    $entry = extLines(extAdjustments($run, 'goodwill')[0]);
    expect($entry['CONS-GOODWILL']['debit'])->toBe('38000.0000')
        ->and($entry['3000']['debit'])->toBe('40000.0000')
        ->and($entry['CONS-PRE-ACQ']['debit'])->toBe('16000.0000')
        ->and($entry['1700']['debit'])->toBe('8000.0000')
        ->and($entry['CONS-DTL']['credit'])->toBe('2000.0000')
        ->and($entry['1500']['credit'])->toBe('100000.0000')
        ->and($entry)->not->toHaveKey('CONS-FV-RES');

    // 120 months: 9 months before the year (2 850) and 6 in it (1 900); impairments 500 earlier, 1 000 this year
    $charges = extLines(extAdjustments($run, 'goodwill_amortisation')[0]);
    expect($charges['CONS-GW-PRIOR']['debit'])->toBe('3350.0000')
        ->and($charges['CONS-GW-AMORT']['debit'])->toBe('1900.0000')
        ->and($charges['CONS-GW-IMP']['debit'])->toBe('1000.0000')
        ->and($charges['CONS-GW-ACC']['credit'])->toBe('6250.0000');

    // minority 20 % of the 80 000 net assets (no fair value for them, OIC 17 para. 99) and of the profit 10 000
    $nci = $run['totals']['non_controlling_interests'][0];
    expect($nci['nci_net_assets'])->toBe('16000.0000')
        ->and($nci['nci_profit'])->toBe('2000.0000')
        ->and($run['totals']['trial_balance_is_balanced'])->toBeTrue()
        ->and($run['totals']['balance_sheet_is_balanced'])->toBeTrue();

    // negative goodwill: a lower cost gives a credit on its own account and no goodwill asset
    test()->deleteJson("/api/v1/general-ledger/consolidation/acquisitions/{$acquisition['id']}")->assertOk();
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", [
        'member_tenant_id' => $this->sub->id, 'acquisition_date' => '2026-01-01', 'cost' => 50000, 'net_assets_at_acquisition' => 70000,
        'recognise_deferred_tax' => true, 'deferred_tax_rate' => 25,
        'fair_value_adjustments' => [['account_code' => '1700', 'account_type' => 'asset', 'amount' => 10000]],
    ])->assertCreated();

    $negative = extLines(extAdjustments(extRun($group)->assertCreated()->json('data'), 'goodwill')[0]);
    expect($negative['CONS-NEG-GW']['credit'])->toBe('12000.0000')->and($negative)->not->toHaveKey('CONS-GOODWILL');

    // deferred tax needs its rate
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", ['member_tenant_id' => $this->parent->id, 'acquisition_date' => '2025-04-01', 'net_assets_at_acquisition' => 1])->assertStatus(422);
});

test('the unrealised margin of inventory and fixed assets is eliminated with the excess depreciation', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-03-01', $this->p['bank'], $this->p['sales'], '12000');
    extPost($this->parent, '2026-03-02', $this->p['cost'], $this->p['bank'], '9000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-03-01', $this->s['inventory'], $this->s['bank'], '12000');
    extPost($this->sub, '2026-01-01', $this->s['fixed'], $this->s['bank'], '10000');
    extPost($this->sub, '2026-06-30', $this->s['dep'], $this->s['bank'], '700');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 100)->assertCreated();
    $url = "/api/v1/general-ledger/consolidation/groups/{$group}/intragroup-transactions";
    $base = ['seller_tenant_id' => $this->parent->id, 'buyer_tenant_id' => $this->sub->id, 'profit_account_id' => $this->p['sales']->id];

    // inventory sold in the year: 3 000 margin, half still in stock
    test()->postJson($url, [...$base, 'asset_type' => 'inventory', 'transaction_date' => '2026-03-01', 'sale_price' => 12000, 'seller_cost' => 9000, 'remaining_percentage' => 50, 'asset_account_id' => $this->s['inventory']->id])->assertCreated();
    // fixed asset sold on 1 January: 4 000 margin over 5 years, 6 months elapsed
    test()->postJson($url, [...$base, 'asset_type' => 'fixed_asset', 'transaction_date' => '2026-01-01', 'margin_amount' => 4000, 'useful_life_years' => 5, 'asset_account_id' => $this->s['fixed']->id, 'depreciation_account_id' => $this->s['dep']->id])->assertCreated();
    // fixed asset sold in the middle of the previous year: 3 000 margin, 12 months elapsed of which 6 earlier
    test()->postJson($url, [...$base, 'asset_type' => 'fixed_asset', 'transaction_date' => '2025-07-01', 'margin_amount' => 3000, 'useful_life_years' => 5, 'asset_account_id' => $this->s['fixed']->id, 'depreciation_account_id' => $this->s['dep']->id])->assertCreated();

    // a fixed asset without life and depreciation account, or with an account of another entity, is refused
    test()->postJson($url, [...$base, 'asset_type' => 'fixed_asset', 'transaction_date' => '2026-01-01', 'margin_amount' => 1, 'asset_account_id' => $this->s['fixed']->id])->assertStatus(422);
    test()->postJson($url, [...$base, 'asset_type' => 'inventory', 'transaction_date' => '2026-03-01', 'margin_amount' => 1, 'asset_account_id' => $this->p['inventory']->id])->assertStatus(422);

    $run = extRun($group)->assertCreated()->json('data');
    $entries = extAdjustments($run, 'unrealised_profit');
    expect($entries)->toHaveCount(3);

    $inventory = collect($entries)->first(fn (array $entry): bool => isset(extLines($entry)['1300']));
    expect(extLines($inventory)['1300']['credit'])->toBe('1500.0000')->and(extLines($inventory)['4000']['debit'])->toBe('1500.0000');

    $current = collect($entries)->first(fn (array $entry): bool => (extLines($entry)['1600']['credit'] ?? null) === '3600.0000');
    expect(extLines($current)['4000']['debit'])->toBe('4000.0000')->and(extLines($current)['6200']['credit'])->toBe('400.0000');

    $prior = collect($entries)->first(fn (array $entry): bool => isset(extLines($entry)['CONS-PRIOR-UNREAL']));
    expect(extLines($prior)['CONS-PRIOR-UNREAL']['debit'])->toBe('2700.0000')
        ->and(extLines($prior)['1600']['credit'])->toBe('2400.0000')
        ->and(extLines($prior)['6200']['credit'])->toBe('300.0000');

    $tb = extTb($run['id']);
    expect($tb['1300']['net'])->toBe('10500.0000')->and($run['totals']['trial_balance_is_balanced'])->toBeTrue();
});

test('an intragroup dividend of the year is eliminated against the income of the receiver', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-05-01', $this->p['bank'], $this->p['div_income'], '5000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '100000');
    extPost($this->sub, '2026-05-01', $this->s['retained'], $this->s['bank'], '5000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 100)->assertCreated();
    $url = "/api/v1/general-ledger/consolidation/groups/{$group}/intragroup-dividends";
    $dividend = ['payer_tenant_id' => $this->sub->id, 'receiver_tenant_id' => $this->parent->id, 'amount' => 5000, 'income_account_id' => $this->p['div_income']->id, 'equity_account_id' => $this->s['retained']->id];

    test()->postJson($url, [...$dividend, 'dividend_date' => '2025-05-01'])->assertCreated();
    test()->postJson($url, [...$dividend, 'dividend_date' => '2026-05-01'])->assertCreated();
    test()->postJson($url, [...$dividend, 'dividend_date' => '2026-05-01', 'income_account_id' => $this->s['sales']->id])->assertStatus(422);
    expect(test()->getJson($url)->assertOk()->json('data'))->toHaveCount(2);

    $run = extRun($group)->assertCreated()->json('data');
    $entries = extAdjustments($run, 'dividend');
    $tb = extTb($run['id']);

    // only the dividend of the year is touched
    expect($entries)->toHaveCount(1)
        ->and(extLines($entries[0])['4200']['debit'])->toBe('5000.0000')
        ->and(extLines($entries[0])['3100']['credit'])->toBe('5000.0000')
        ->and($tb['4200']['net'])->toBe('0.0000')
        ->and($tb['3100']['net'])->toBe('0.0000');
});

test('effective ownership follows the chain of holdings and a cycle is refused', function (): void {
    $b = extTenant('Sub B');
    $c = extTenant('Sub C');

    foreach ([$b, $c] as $tenant) {
        $this->accountant->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    }

    $pb = extChart($b);
    $pc = extChart($c);
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-01-05', $this->p['invest'], $this->p['bank'], '80000');
    extPost($b, '2026-01-02', $pb['bank'], $pb['capital'], '100000');
    extPost($b, '2026-01-05', $pb['invest'], $pb['bank'], '50000');
    extPost($c, '2026-01-02', $pc['bank'], $pc['capital'], '100000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $b, 80)->assertCreated();
    extMember($group, $c, 50, 'full', ['holder_tenant_id' => $b->id])->assertCreated();
    extMap(extRelation($group, $this->parent, $b), 'investment_equity', $this->p['invest']->id, $pb['capital']->id);
    extMap(extRelation($group, $b, $c), 'investment_equity', $pb['invest']->id, $pc['capital']->id);

    $members = collect(test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.members'));
    $memberB = $members->firstWhere('member_tenant_id', $b->id);

    // B cannot be held by C, which B holds: a cycle; nobody holds himself; a holder must be in the group
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberB['id']}", ['ownership_percentage' => 80, 'consolidation_method' => 'full', 'holder_tenant_id' => $c->id])->assertStatus(422);
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberB['id']}", ['ownership_percentage' => 80, 'consolidation_method' => 'full', 'holder_tenant_id' => $b->id])->assertStatus(422);
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberB['id']}", ['ownership_percentage' => 80, 'consolidation_method' => 'equity'])->assertStatus(422);
    test()->deleteJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberB['id']}")->assertStatus(409);

    $run = extRun($group)->assertCreated()->json('data');
    $effective = collect($run['members'])->pluck('effective_ownership', 'tenant_id');
    $tb = extTb($run['id']);
    $sheet = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->json('data');

    expect($effective[$b->id])->toBe('80.0000')
        ->and($effective[$c->id])->toBe('40.0000')
        ->and($tb['1000']['net'])->toBe('270000.0000')
        ->and($tb['3000']['net'])->toBe('-270000.0000')
        ->and($tb['1500']['net'])->toBe('0.0000');

    // minorities: 60 % of C (100 000) and 20 % of B without its investment in C (50 000)
    $nci = collect($run['totals']['non_controlling_interests'])->pluck('nci_net_assets', 'member_tenant_id');
    expect($nci[$c->id])->toBe('60000.0000')
        ->and($nci[$b->id])->toBe('10000.0000')
        ->and($sheet['non_controlling_interests'])->toBe('70000.0000')
        ->and($sheet['equity_attributable_to_owners'])->toBe('200000.0000')
        ->and($sheet['is_balanced'])->toBeTrue();
});

test('accounts are mapped to the group chart, mappings can be required, and a year offset is aligned', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '100000');
    extPost($this->parent, '2026-03-01', $this->p['bank'], $this->p['sales'], '3000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-02-10', $this->s['bank'], $this->s['alt_sales'], '10000');
    extPost($this->sub, '2026-05-10', $this->s['bank'], $this->s['alt_sales'], '4000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 100)->assertCreated();
    $mapping = test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/account-mappings", ['member_tenant_id' => $this->sub->id, 'account_id' => $this->s['alt_sales']->id, 'group_account_code' => '4000', 'group_account_name' => 'Sales'])->assertCreated()->json('data');
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/account-mappings", ['member_tenant_id' => $this->sub->id, 'account_id' => $this->s['alt_sales']->id, 'group_account_code' => '4001'])->assertStatus(409);
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/account-mappings", ['member_tenant_id' => $this->sub->id, 'account_id' => $this->p['sales']->id, 'group_account_code' => '4000'])->assertStatus(422);

    // the whole year of the subsidiary (both postings) under the parent code, nothing under its own
    $tb = extTb(extRun($group)->assertCreated()->json('data.id'));
    expect($tb['4000']['net'])->toBe('-17000.0000')->and($tb)->not->toHaveKey('7000');

    // when mappings are required, accounts with a balance that are not mapped stop the run
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}", ['require_account_mapping' => true])->assertOk();
    extRun($group)->assertStatus(422);
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}", ['require_account_mapping' => false])->assertOk();
    test()->deleteJson("/api/v1/general-ledger/consolidation/account-mappings/{$mapping['id']}")->assertOk();

    // a subsidiary whose year starts in April contributes April to June only; the earlier result is unclosed
    $memberId = collect(test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.members'))->firstWhere('member_tenant_id', $this->sub->id)['id'];
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberId}", ['ownership_percentage' => 100, 'consolidation_method' => 'full', 'fiscal_year_offset_months' => 3])->assertOk();

    $run = extRun($group)->assertCreated()->json('data');
    $tb = extTb($run['id']);
    $codes = collect($run['warnings'])->pluck('code')->all();
    expect($tb['7000']['net'])->toBe('-4000.0000')
        ->and($tb['CONS-RETAINED']['net'])->toBe('-10000.0000')
        ->and($codes)->toContain('member_fiscal_year_mismatch')->and($codes)->toContain('unclosed_prior_results')
        ->and(collect($run['members'])->firstWhere('tenant_id', $this->sub->id)['year_start'])->toBe('2026-04-01');

    // a real fiscal year that starts on another day cannot be aligned with the month grid: refused
    FiscalYear::withoutGlobalScopes()->where('tenant_id', $this->sub->id)->update(['start_date' => '2026-01-15']);
    extRun($group)->assertStatus(422);
});

test('several investment accounts of the investor are eliminated together against the equity of the investee', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-01-05', $this->p['invest'], $this->p['bank'], '60000');
    extPost($this->parent, '2026-02-05', $this->p['invest2'], $this->p['bank'], '20000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '100000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 80)->assertCreated();
    $relation = extRelation($group, $this->parent, $this->sub);
    extMap($relation, 'investment_equity', $this->p['invest']->id, $this->s['capital']->id);
    extMap($relation, 'investment_equity', $this->p['invest2']->id, $this->s['capital']->id);

    $run = extRun($group)->assertCreated()->json('data');
    $entries = extAdjustments($run, 'investment_equity');
    $lines = extLines($entries[0]);

    // one elimination: 80 % of 100 000 equity against 60 000 + 20 000, no difference
    expect($entries)->toHaveCount(1)
        ->and($lines['3000']['debit'])->toBe('80000.0000')
        ->and($lines['1500']['credit'])->toBe('60000.0000')
        ->and($lines['1510']['credit'])->toBe('20000.0000')
        ->and($lines)->not->toHaveKey('CONS-DIFF');
});

test('the elimination policy posts the intercompany difference to its account when asked', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '100000');
    extPost($this->parent, '2026-03-01', $this->p['receivable'], $this->p['ic_sales'], '10000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-03-01', $this->s['cost'], $this->s['payable'], '9500');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 100)->assertCreated();
    extMap(extRelation($group, $this->parent, $this->sub), 'receivable_payable', $this->p['receivable']->id, $this->s['payable']->id);

    // default: the smaller balance is eliminated and the difference stays in the receivable
    $kept = extTb(extRun($group)->assertCreated()->json('data.id'));
    expect($kept['1150']['net'])->toBe('500.0000')->and($kept['2150']['net'])->toBe('0.0000')->and($kept)->not->toHaveKey('CONS-IC-DIFF');

    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}", ['elimination_difference_policy' => 'post_to_difference_account'])->assertOk();
    $run = extRun($group)->assertCreated()->json('data');
    $posted = extTb($run['id']);

    expect($posted['1150']['net'])->toBe('0.0000')
        ->and($posted['2150']['net'])->toBe('0.0000')
        ->and($posted['CONS-IC-DIFF']['net'])->toBe('500.0000')
        ->and($run['totals']['trial_balance_is_balanced'])->toBeTrue();
});

test('runs made without the extensions keep the fingerprint they had', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '100000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 100)->assertCreated();

    $run = extRun($group)->assertCreated()->json('data');

    expect($run['input_fingerprint'])->toHaveLength(64)
        ->and(DB::table('gl_consolidation_runs')->where('id', $run['id'])->value('inputs_snapshot'))->toBe('[]')
        ->and(test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/verify")->json('data.matches'))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $adjustment
 * @return array<string, string> Debit minus credit by account code
 */
function extNets(array $adjustment): array
{
    $nets = [];

    foreach ($adjustment['lines'] as $line) {
        $nets[$line['account_code']] = bcadd($nets[$line['account_code']] ?? '0', bcsub($line['debit'], $line['credit'], 4), 4);
    }

    return $nets;
}

test('fair-value adjustments are released over their useful life with the deferred tax and without reaching the minority', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '300000');
    extPost($this->parent, '2026-01-05', $this->p['invest'], $this->p['bank'], '100000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '70000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 80)->assertCreated();
    extMap(extRelation($group, $this->parent, $this->sub), 'investment_equity', $this->p['invest']->id, $this->s['capital']->id);
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", [
        'member_tenant_id' => $this->sub->id, 'acquisition_date' => '2025-04-01', 'net_assets_at_acquisition' => 70000,
        'recognise_deferred_tax' => true, 'deferred_tax_rate' => 25,
        'fair_value_adjustments' => [
            ['account_code' => '1700', 'account_name' => 'Building', 'account_type' => 'asset', 'amount' => 12000, 'useful_life_years' => 6],
            ['account_code' => '1710', 'account_name' => 'Land', 'account_type' => 'asset', 'amount' => 1000],
        ],
    ])->assertCreated();

    // pro rata from 1 April 2025: 9 months before the year and 6 in it, over 72 months, on 80 % of 12 000
    $run = extRun($group)->assertCreated()->json('data');
    $release = extNets(extAdjustments($run, 'fair_value_release')[0]);

    expect(extAdjustments($run, 'fair_value_release'))->toHaveCount(1)
        ->and($release['1700'])->toBe('-2000.0000')
        ->and($release['CONS-FV-DEPR'])->toBe('800.0000')
        ->and($release['CONS-FV-PRIOR'])->toBe('900.0000')
        ->and($release['CONS-DTL'])->toBe('500.0000')
        ->and($release['CONS-DEFTAX-PL'])->toBe('-200.0000');

    // goodwill is untouched by the release: 100 000 - 80 % x (70 000 + 13 000 - 3 250)
    $goodwill = extLines(extAdjustments($run, 'goodwill')[0]);
    expect($goodwill['CONS-GOODWILL']['debit'])->toBe('36200.0000');

    // the minority share does not include fair value: 20 % of the 70 000 net assets
    expect($run['totals']['non_controlling_interests'][0]['nci_net_assets'])->toBe('14000.0000')
        ->and($run['totals']['trial_balance_is_balanced'])->toBeTrue();

    // the other first-year rule counts the whole acquisition year: 12 months before the year
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}", ['fair_value_first_year_rule' => 'full_year'])->assertOk();
    $full = extNets(extAdjustments(extRun($group)->assertCreated()->json('data'), 'fair_value_release')[0]);

    expect($full['1700'])->toBe('-2400.0000')
        ->and($full['CONS-FV-DEPR'])->toBe('800.0000')
        ->and($full['CONS-FV-PRIOR'])->toBe('1200.0000')
        ->and($full['CONS-DTL'])->toBe('600.0000');
});

test('deferred tax on unrealised profit, the minority bearing an upstream sale and a transaction before the acquisition', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-03-01', $this->p['inventory'], $this->p['bank'], '12000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-03-01', $this->s['bank'], $this->s['sales'], '12000');
    extPost($this->sub, '2026-03-02', $this->s['cost'], $this->s['bank'], '9000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup(['deferred_tax_on_unrealised_profit' => true]);
    extMember($group, $this->sub, 80)->assertCreated();
    $url = "/api/v1/general-ledger/consolidation/groups/{$group}/intragroup-transactions";
    // upstream: the 80 % subsidiary sold goods with a margin of 3 000 to the parent
    test()->postJson($url, ['seller_tenant_id' => $this->sub->id, 'buyer_tenant_id' => $this->parent->id, 'asset_type' => 'inventory', 'transaction_date' => '2026-03-01', 'sale_price' => 12000, 'seller_cost' => 9000, 'asset_account_id' => $this->p['inventory']->id, 'profit_account_id' => $this->s['sales']->id])->assertCreated();

    // the toggle is on and no rate exists: no rate is assumed
    extRun($group)->assertStatus(422);

    $parentMember = collect(test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.members'))->firstWhere('is_parent', true)['id'];
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$parentMember}", ['ownership_percentage' => 100, 'consolidation_method' => 'full'])->assertStatus(422);
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}", ['unrealised_profit_tax_rate' => 25])->assertOk();

    $run = extRun($group)->assertCreated()->json('data');
    $entry = extNets(extAdjustments($run, 'unrealised_profit')[0]);

    // margin 3 000 removed from the inventory of the parent, tax at 25 % on it deferred
    expect($entry['1300'])->toBe('-3000.0000')
        ->and($entry['4000'])->toBe('3000.0000')
        ->and($entry['CONS-DTA'])->toBe('750.0000')
        ->and($entry['CONS-DEFTAX-PL'])->toBe('-750.0000');

    $sheet = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->json('data');
    $income = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/income-statement")->json('data');
    expect($sheet['deferred_tax_asset'])->toBe('750.0000')
        ->and($sheet['is_balanced'])->toBeTrue()
        ->and($income['income_tax'])->toBe('-750.0000')
        ->and($income['net_income'])->toBe('750.0000');

    // upstream: the minority (20 %) bears its share of the profit net of tax (2 250) and of the net assets (53 000 - 2 250)
    $nci = $run['totals']['non_controlling_interests'][0];
    expect($nci['nci_net_assets'])->toBe('10150.0000')->and($nci['nci_profit'])->toBe('150.0000');

    // a transaction dated before the acquisition of one of its parties is not eliminated (OIC 17 para. 47)
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", ['member_tenant_id' => $this->sub->id, 'acquisition_date' => '2026-04-01', 'net_assets_at_acquisition' => 50000])->assertCreated();
    $skipped = extRun($group)->assertCreated()->json('data');
    expect(extAdjustments($skipped, 'unrealised_profit'))->toBe([])
        ->and(collect($skipped['warnings'])->pluck('code')->all())->toContain('transaction_before_consolidation');
});

test('a sale from the parent leaves the minority interests alone', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '200000');
    extPost($this->parent, '2026-03-01', $this->p['bank'], $this->p['sales'], '12000');
    extPost($this->parent, '2026-03-02', $this->p['cost'], $this->p['bank'], '9000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-03-01', $this->s['inventory'], $this->s['bank'], '12000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup(['deferred_tax_on_unrealised_profit' => true, 'unrealised_profit_tax_rate' => 25]);
    extMember($group, $this->sub, 80, 'full', ['tax_rate' => 20])->assertCreated();
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/intragroup-transactions", ['seller_tenant_id' => $this->parent->id, 'buyer_tenant_id' => $this->sub->id, 'asset_type' => 'inventory', 'transaction_date' => '2026-03-01', 'margin_amount' => 3000, 'asset_account_id' => $this->s['inventory']->id, 'profit_account_id' => $this->p['sales']->id])->assertCreated();

    $run = extRun($group)->assertCreated()->json('data');
    $entry = extNets(extAdjustments($run, 'unrealised_profit')[0]);
    $nci = $run['totals']['non_controlling_interests'][0];

    // the buying member has its own rate (20 %), which wins over the group rate
    expect($entry['1300'])->toBe('-3000.0000')
        ->and($entry['CONS-DTA'])->toBe('600.0000')
        ->and($nci['nci_net_assets'])->toBe('10000.0000')
        ->and($nci['nci_profit'])->toBe('0.0000');
});

test('acquisition amounts in a local currency are translated at the acquisition date and goodwill can be retranslated', function (): void {
    $k = Tenant::create(['name' => 'Elvetica', 'legal_name' => 'Elvetica AG', 'default_currency' => 'CHF']);
    $this->accountant->tenants()->attach($k->id, ['id' => (string) Str::uuid(), 'role' => 'accountant']);
    $pk = extChart($k);

    foreach ([['2025-04-01', '0.900000'], ['2026-01-01', '1.000000'], ['2026-06-30', '1.100000']] as [$date, $rate]) {
        ExchangeRate::query()->create(['source' => 'CHF', 'target' => 'EUR', 'rate' => $rate, 'effective_date' => $date]);
    }

    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '300000');
    extPost($this->parent, '2026-01-05', $this->p['invest'], $this->p['bank'], '108000');
    extPost($k, '2026-01-02', $pk['bank'], $pk['capital'], '100000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $k, 100)->assertCreated();
    extMap(extRelation($group, $this->parent, $k), 'investment_equity', $this->p['invest']->id, $pk['capital']->id);
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/acquisitions", [
        'member_tenant_id' => $k->id, 'acquisition_date' => '2025-04-01', 'input_currency' => 'CHF', 'cost' => 120000, 'net_assets_at_acquisition' => 100000,
        'fair_value_adjustments' => [['account_code' => '1700', 'account_type' => 'asset', 'amount' => 10000]],
    ])->assertCreated();

    // CHF 120 000 - CHF 110 000 = CHF 10 000 of goodwill at 0.90 = 9 000; fair value 9 000
    $run = extRun($group)->assertCreated()->json('data');
    $entry = extLines(extAdjustments($run, 'goodwill')[0]);
    $retranslations = fn (array $r): array => array_values(array_filter(extAdjustments($r, 'translation_difference'), fn (array $a): bool => str_contains($a['description'], 'Retranslation')));

    expect($entry['CONS-GOODWILL']['debit'])->toBe('9000.0000')
        ->and($entry['1700']['debit'])->toBe('9000.0000')
        ->and($retranslations($run))->toBe([]);

    // retranslated at the closing rate 1.10: 9 000 x 1.10 / 0.90 = 11 000 each, the difference to the translation reserve
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}", ['goodwill_translation' => 'closing'])->assertOk();
    $closing = extRun($group)->assertCreated()->json('data');
    $lines = extLines($retranslations($closing)[0]);

    expect($lines['CONS-GOODWILL']['debit'])->toBe('2000.0000')
        ->and($lines['1700']['debit'])->toBe('2000.0000')
        ->and($lines['CONS-CTA']['credit'])->toBe('4000.0000')
        ->and($closing['totals']['trial_balance_is_balanced'])->toBeTrue();

    // without a rate on the acquisition date nothing is guessed
    ExchangeRate::query()->whereDate('effective_date', '2025-04-01')->delete();
    extRun($group)->assertStatus(422);
});

test('the intercompany reconciliation reads a member from its own year and shows the mapped code', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '100000');
    extPost($this->parent, '2026-03-01', $this->p['receivable'], $this->p['ic_sales'], '3000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extPost($this->sub, '2026-02-10', $this->s['cost'], $this->s['bank'], '2000');
    extPost($this->sub, '2026-05-10', $this->s['cost'], $this->s['bank'], '3000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 100)->assertCreated();
    extMap(extRelation($group, $this->parent, $this->sub), 'revenue_cost', $this->p['ic_sales']->id, $this->s['cost']->id);
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/account-mappings", ['member_tenant_id' => $this->sub->id, 'account_id' => $this->s['cost']->id, 'group_account_code' => '6999', 'group_account_name' => 'Group cost'])->assertCreated();
    $period = (string) FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->parent->id)->whereDate('start_date', '<=', '2026-06-30')->whereDate('end_date', '>=', '2026-06-30')->firstOrFail()->id;
    $report = fn (): array => test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}/intercompany-reconciliation?fiscal_period_id={$period}")->assertOk()->json('data.rows.0');

    // the year of the subsidiary starts in January: 5 000 of cost against 3 000 of revenue
    expect($report()['difference'])->toBe('-2000.0000')
        ->and($report()['entity_b']['account_code'])->toBe('6999')
        ->and($report()['entity_b']['year_start'])->toBe('2026-01-01');

    // with the offset of the run only April to June counts: the pair matches
    $memberId = collect(test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.members'))->firstWhere('member_tenant_id', $this->sub->id)['id'];
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberId}", ['ownership_percentage' => 100, 'consolidation_method' => 'full', 'fiscal_year_offset_months' => 3])->assertOk();

    expect($report()['difference'])->toBe('0.0000')
        ->and($report()['status'])->toBe('matched')
        ->and($report()['entity_b']['year_start'])->toBe('2026-04-01');
});

test('a proportional member under twenty percent is reported', function (): void {
    extPost($this->parent, '2026-01-02', $this->p['bank'], $this->p['capital'], '100000');
    extPost($this->sub, '2026-01-02', $this->s['bank'], $this->s['capital'], '50000');
    extActAs($this->accountant, $this->parent);

    $group = extGroup();
    extMember($group, $this->sub, 15, 'proportional')->assertCreated();

    $codes = collect(extRun($group)->assertCreated()->json('data.warnings'))->pluck('code')->all();
    expect($codes)->toContain('proportional_below_threshold');
});
