<?php

declare(strict_types=1);

use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\ConsolidationMethod;
use Modules\GeneralLedger\Http\Middleware\RejectApiKeyAccess;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\GeneralLedger\Models\ConsolidationMember;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\IntercompanyRelation;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\StatementScheme;
use Modules\GeneralLedger\Models\StatementSchemeRow;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/**
 * Accounting consolidation: groups, intercompany relations and mappings, the reconciliation report, runs with
 * eliminations stored apart from the ledgers, statements, lock and verify, and the tenant safety of all of it.
 *
 * The main scenario (June 2026, group currency EUR). Parent (EUR) owns 80 % of Italia (EUR, cost 80 000 against capital
 * 100 000) and 100 % of Svizzera (CHF, cost 55 000 EUR against capital 50 000 CHF). CHF/EUR is 1.00 on 1 January,
 * 1.05 on 31 March and 1.10 on 30 June, so the closing rate is 1.10, the average of the published rates 1.05 and the
 * historical rate 1.00. Parent sold services for 10 000 to Italia (matched), Italia sold goods for 2 200 EUR to Svizzera,
 * which booked 2 000 CHF: the balance sheet pair matches at the closing rate (2 200), the revenue and cost pair differs
 * (2 200 against 2 100 at the average rate).
 */
function conMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9)]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function conGrant(User $user, Tenant $tenant, string $role): void
{
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
}

function conActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function conAccount(Tenant $tenant, string $code, string $type, string $normal): ChartOfAccount
{
    return ChartOfAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'company_id' => null,
        'account_code' => $code,
        'name' => "Account {$code}",
        'account_type' => $type,
        'account_class' => $type,
        'normal_balance' => $normal,
        'is_active' => true,
        'allows_manual_posting' => true,
    ]);
}

function conPost(Tenant $tenant, string $date, ChartOfAccount $debit, ChartOfAccount $credit, string $amount): void
{
    app(TenantContext::class)->setTenant($tenant);
    $period = FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail();

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $tenant->id,
        'fiscal_period_id' => $period->id,
        'posting_date' => $date,
        'description' => 'Consolidation test posting',
        'bank_balance_managed' => true,
        'lines' => [
            ['account_id' => $debit->id, 'debit_amount' => $amount, 'credit_amount' => '0'],
            ['account_id' => $credit->id, 'debit_amount' => '0', 'credit_amount' => $amount],
        ],
    ]);
    app(TenantContext::class)->clear();
}

/**
 * The chart used by every entity: the same codes, as the group is expected to align them.
 *
 * @return array<string, ChartOfAccount>
 */
function conChart(Tenant $tenant): array
{
    app(TenantContext::class)->setTenant($tenant);
    app(OpenFiscalYearAction::class)->execute((string) $tenant->id, 2026);
    app(TenantContext::class)->clear();

    return [
        'bank' => conAccount($tenant, '1000', 'asset', 'debit'),
        'receivable' => conAccount($tenant, '1150', 'asset', 'debit'),
        'invest_it' => conAccount($tenant, '1500', 'asset', 'debit'),
        'invest_ch' => conAccount($tenant, '1510', 'asset', 'debit'),
        'payable' => conAccount($tenant, '2150', 'liability', 'credit'),
        'capital' => conAccount($tenant, '3000', 'equity', 'credit'),
        'sales' => conAccount($tenant, '4000', 'revenue', 'credit'),
        'ic_sales' => conAccount($tenant, '4100', 'revenue', 'credit'),
        'cost' => conAccount($tenant, '6000', 'expense', 'debit'),
        'ic_cost' => conAccount($tenant, '6100', 'expense', 'debit'),
    ];
}

function conPeriodId(Tenant $tenant, string $date): string
{
    return (string) FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->firstOrFail()->id;
}

/**
 * @param  array<string, mixed>  $payload
 */
function conPostJson(string $uri, array $payload = []): TestResponse
{
    return test()->postJson($uri, $payload);
}

beforeEach(function (): void {
    $this->parent = Tenant::create(['name' => 'Capogruppo', 'legal_name' => 'Capogruppo SpA', 'default_currency' => 'EUR']);
    $this->italia = Tenant::create(['name' => 'Italia', 'legal_name' => 'Italia Srl', 'default_currency' => 'EUR']);
    $this->svizzera = Tenant::create(['name' => 'Svizzera', 'legal_name' => 'Svizzera AG', 'default_currency' => 'CHF']);
    $this->outsider = Tenant::create(['name' => 'Estranea', 'legal_name' => 'Estranea SpA', 'default_currency' => 'EUR']);

    $this->accountant = conMember($this->parent, 'accountant');
    conGrant($this->accountant, $this->italia, 'accountant');
    conGrant($this->accountant, $this->svizzera, 'accountant');
    conGrant($this->accountant, $this->outsider, 'accountant');

    $this->parentChart = conChart($this->parent);
    $this->italiaChart = conChart($this->italia);
    $this->svizzeraChart = conChart($this->svizzera);
    $this->outsiderChart = conChart($this->outsider);

    foreach ([['2026-01-01', '1.000000'], ['2026-03-31', '1.050000'], ['2026-06-30', '1.100000']] as [$date, $rate]) {
        ExchangeRate::query()->create(['source' => 'CHF', 'target' => 'EUR', 'rate' => $rate, 'effective_date' => $date]);
    }

    $p = $this->parentChart;
    conPost($this->parent, '2026-01-02', $p['bank'], $p['capital'], '200000');
    conPost($this->parent, '2026-01-05', $p['invest_it'], $p['bank'], '80000');
    conPost($this->parent, '2026-01-06', $p['invest_ch'], $p['bank'], '55000');
    conPost($this->parent, '2026-03-10', $p['receivable'], $p['ic_sales'], '10000');
    conPost($this->parent, '2026-03-12', $p['bank'], $p['sales'], '20000');
    conPost($this->parent, '2026-03-15', $p['cost'], $p['bank'], '5000');

    $i = $this->italiaChart;
    conPost($this->italia, '2026-01-02', $i['bank'], $i['capital'], '100000');
    conPost($this->italia, '2026-03-10', $i['ic_cost'], $i['payable'], '10000');
    conPost($this->italia, '2026-03-12', $i['bank'], $i['sales'], '30000');
    conPost($this->italia, '2026-04-10', $i['receivable'], $i['ic_sales'], '2200');

    $s = $this->svizzeraChart;
    conPost($this->svizzera, '2026-01-02', $s['bank'], $s['capital'], '50000');
    conPost($this->svizzera, '2026-04-10', $s['ic_cost'], $s['payable'], '2000');
    conPost($this->svizzera, '2026-05-12', $s['bank'], $s['sales'], '10000');

    $o = $this->outsiderChart;
    conPost($this->outsider, '2026-02-02', $o['bank'], $o['sales'], '777777');

    $this->juneParent = conPeriodId($this->parent, '2026-06-30');
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Builds the group of the main scenario through the API and returns its id.
 */
function conBuildGroup(): string
{
    $test = test();
    conActAs($test->accountant, $test->parent);

    $group = (string) conPostJson('/api/v1/general-ledger/consolidation/groups', ['name' => 'Gruppo Alfa', 'group_currency' => 'EUR'])->assertCreated()->json('data.id');
    conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/members", ['member_tenant_id' => $test->italia->id, 'ownership_percentage' => 80, 'consolidation_method' => 'full'])->assertCreated();
    conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/members", ['member_tenant_id' => $test->svizzera->id, 'ownership_percentage' => 100, 'consolidation_method' => 'full'])->assertCreated();

    $parentItalia = (string) conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/relations", ['entity_a_tenant_id' => $test->parent->id, 'entity_b_tenant_id' => $test->italia->id])->assertCreated()->json('data.id');
    $italiaSvizzera = (string) conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/relations", ['entity_a_tenant_id' => $test->italia->id, 'entity_b_tenant_id' => $test->svizzera->id])->assertCreated()->json('data.id');
    $parentSvizzera = (string) conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/relations", ['entity_a_tenant_id' => $test->parent->id, 'entity_b_tenant_id' => $test->svizzera->id])->assertCreated()->json('data.id');

    $p = $test->parentChart;
    $i = $test->italiaChart;
    $s = $test->svizzeraChart;
    $map = static fn (string $relation, string $type, string $a, ?string $b) => conPostJson("/api/v1/general-ledger/consolidation/relations/{$relation}/mappings", ['mapping_type' => $type, 'account_a_id' => $a, 'account_b_id' => $b])->assertCreated();

    $map($parentItalia, 'receivable_payable', $p['receivable']->id, $i['payable']->id);
    $map($parentItalia, 'revenue_cost', $p['ic_sales']->id, $i['ic_cost']->id);
    $map($parentItalia, 'investment_equity', $p['invest_it']->id, $i['capital']->id);
    $map($italiaSvizzera, 'receivable_payable', $i['receivable']->id, $s['payable']->id);
    $map($italiaSvizzera, 'revenue_cost', $i['ic_sales']->id, $s['ic_cost']->id);
    $map($parentSvizzera, 'investment_equity', $p['invest_ch']->id, $s['capital']->id);

    return $group;
}

function conRun(string $group): TestResponse
{
    $test = test();
    conActAs($test->accountant, $test->parent);

    return conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/runs", ['fiscal_period_id' => $test->juneParent]);
}

/**
 * @return array<string, array<string, mixed>> The accounts of the consolidated trial balance by code
 */
function conTrialBalance(string $runId): array
{
    $accounts = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$runId}/trial-balance")->assertOk()->json('data.accounts');

    return collect($accounts)->keyBy('account_code')->all();
}

test('a group is created with its parent as full member and members are added with their share', function (): void {
    $group = conBuildGroup();

    $data = test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->assertOk()->json('data');

    expect($data['group_currency'])->toBe('EUR')
        ->and($data['members'])->toHaveCount(3)
        ->and($data['members'][0]['is_parent'])->toBeTrue()
        ->and($data['members'][0]['member_tenant_id'])->toBe($this->parent->id)
        ->and($data['members'][0]['consolidation_method'])->toBe('full')
        ->and(collect($data['members'])->firstWhere('member_tenant_id', $this->italia->id)['ownership_percentage'])->toBe('80.0000')
        ->and($data['relations'])->toHaveCount(3);

    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$data['members'][0]['id']}", ['ownership_percentage' => 60, 'consolidation_method' => 'full'])->assertStatus(422);
    test()->deleteJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$data['members'][0]['id']}")->assertStatus(422);
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/members", ['member_tenant_id' => $this->italia->id, 'ownership_percentage' => 10, 'consolidation_method' => 'full'])->assertStatus(409);
});

test('mappings must fit the accounts and the entities of the relation', function (): void {
    $group = conBuildGroup();
    $relation = (string) test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.relations.0.id');
    $entityA = (string) test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.relations.0.entity_a_tenant_id');
    $url = "/api/v1/general-ledger/consolidation/relations/{$relation}/mappings";
    $aChart = $entityA === $this->parent->id ? $this->parentChart : $this->italiaChart;

    // an account of an entity outside the relation
    test()->postJson($url, ['mapping_type' => 'receivable_payable', 'account_a_id' => $this->outsiderChart['receivable']->id, 'account_b_id' => $this->italiaChart['payable']->id])->assertStatus(422);
    // two assets are not a receivable/payable pair
    test()->postJson($url, ['mapping_type' => 'receivable_payable', 'account_a_id' => $aChart['receivable']->id, 'account_b_id' => $this->italiaChart['bank']->id])->assertStatus(422);
    // a revenue needs a cost on the other side
    test()->postJson($url, ['mapping_type' => 'revenue_cost', 'account_a_id' => $aChart['ic_sales']->id, 'account_b_id' => $this->italiaChart['sales']->id])->assertStatus(422);
    // a duplicate
    test()->postJson($url, ['mapping_type' => 'receivable_payable', 'account_a_id' => $this->parentChart['receivable']->id, 'account_b_id' => $this->italiaChart['payable']->id])->assertStatus(409);
});

test('the reconciliation report shows matched pairs and highlights the differences', function (): void {
    $group = conBuildGroup();

    $report = test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}/intercompany-reconciliation?fiscal_period_id={$this->juneParent}")->assertOk()->json('data');
    $rows = collect($report['rows']);

    expect($report['currency'])->toBe('EUR')
        ->and($report['summary']['pairs'])->toBe(4)
        ->and($report['summary']['pairs_with_difference'])->toBe(1);

    $parentItalia = $rows->where('mapping_type', 'receivable_payable')->first(fn (array $row): bool => $row['entity_a']['tenant_id'] === $this->parent->id);
    expect($parentItalia['entity_a']['group_amount'])->toBe('10000.0000')
        ->and($parentItalia['entity_b']['group_amount'])->toBe('10000.0000')
        ->and($parentItalia['status'])->toBe('matched');

    $balanceFx = $rows->where('mapping_type', 'receivable_payable')->first(fn (array $row): bool => $row['entity_a']['tenant_id'] === $this->italia->id);
    expect($balanceFx['entity_b']['local_amount'])->toBe('2000.0000')
        ->and($balanceFx['entity_b']['local_currency'])->toBe('CHF')
        ->and($balanceFx['entity_b']['group_amount'])->toBe('2200.0000')
        ->and($balanceFx['status'])->toBe('matched');

    $costFx = $rows->where('mapping_type', 'revenue_cost')->first(fn (array $row): bool => $row['entity_a']['tenant_id'] === $this->italia->id);
    expect($costFx['entity_a']['group_amount'])->toBe('2200.0000')
        ->and($costFx['entity_b']['group_amount'])->toBe('2100.0000')
        ->and($costFx['difference'])->toBe('100.0000')
        ->and($costFx['has_difference'])->toBeTrue()
        ->and($costFx['status'])->toBe('difference')
        ->and($report['summary']['absolute_difference']['revenue_cost'])->toBe('100.0000');
});

test('a run translates, aggregates and eliminates, and the consolidated statements balance', function (): void {
    $group = conBuildGroup();
    $entriesBefore = JournalEntry::withoutGlobalScopes()->count();
    $accountsBefore = ChartOfAccount::withoutGlobalScopes()->count();

    $run = conRun($group)->assertCreated()->json('data');

    expect($run['version'])->toBe(1)
        ->and($run['status'])->toBe('completed')
        ->and($run['group_currency'])->toBe('EUR')
        ->and($run['totals']['trial_balance_is_balanced'])->toBeTrue()
        ->and($run['totals']['balance_sheet_is_balanced'])->toBeTrue()
        ->and(collect($run['rates'])->firstWhere('tenant_id', $this->svizzera->id))->toMatchArray(['closing' => '1.100000', 'average' => '1.050000', 'historical' => '1.000000']);

    // nothing was posted into any entity and no consolidation account entered a chart of accounts
    expect(JournalEntry::withoutGlobalScopes()->count())->toBe($entriesBefore)
        ->and(ChartOfAccount::withoutGlobalScopes()->count())->toBe($accountsBefore)
        ->and(ChartOfAccount::withoutGlobalScopes()->where('account_code', 'like', 'CONS-%')->count())->toBe(0);

    $types = collect($run['adjustments'])->countBy('adjustment_type')->all();
    ksort($types);
    expect($types)->toBe(['intercompany_balance' => 2, 'intercompany_revenue_cost' => 2, 'investment_equity' => 2, 'translation_difference' => 1]);

    $tb = conTrialBalance($run['id']);
    expect($tb['1000']['net'])->toBe('276000.0000')
        ->and($tb['1150']['net'])->toBe('0.0000')
        ->and($tb['1500']['net'])->toBe('0.0000')
        ->and($tb['1510']['net'])->toBe('0.0000')
        ->and($tb['2150']['net'])->toBe('0.0000')
        ->and($tb['3000']['net'])->toBe('-220000.0000')
        ->and($tb['CONS-CTA']['net'])->toBe('-5400.0000')
        ->and($tb['CONS-DIFF']['net'])->toBe('5000.0000')
        ->and($tb['4000']['net'])->toBe('-60500.0000')
        ->and($tb['4100']['net'])->toBe('-100.0000')
        ->and($tb['6000']['net'])->toBe('5000.0000')
        ->and($tb['6100']['net'])->toBe('0.0000');

    $trial = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/trial-balance")->json('data');
    expect($trial['is_balanced'])->toBeTrue()->and($trial['total_debit'])->toBe($trial['total_credit']);

    $sheet = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->assertOk()->json('data');
    expect($sheet['total_assets'])->toBe('281000.0000')
        ->and($sheet['total_liabilities'])->toBe('0.0000')
        ->and($sheet['total_equity_reserves'])->toBe('225400.0000')
        ->and($sheet['result_of_the_period'])->toBe('55600.0000')
        ->and($sheet['total_equity'])->toBe('281000.0000')
        ->and($sheet['non_controlling_interests'])->toBe('24440.0000')
        ->and($sheet['equity_attributable_to_owners'])->toBe('256560.0000')
        ->and($sheet['is_balanced'])->toBeTrue();

    $income = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/income-statement")->assertOk()->json('data');
    expect($income['total_revenue'])->toBe('60600.0000')
        ->and($income['total_operating_expenses'])->toBe('5000.0000')
        ->and($income['net_income'])->toBe('55600.0000')
        ->and($income['net_income_attributable_to_non_controlling_interests'])->toBe('4440.0000')
        ->and($income['net_income_attributable_to_owners'])->toBe('51160.0000');

    $codes = collect($run['warnings'])->pluck('code')->all();
    expect($codes)->toContain('intercompany_difference')->and($codes)->toContain('member_period_open');
    $difference = collect($run['warnings'])->firstWhere('code', 'intercompany_difference');
    expect($difference['difference'])->toBe('100.0000');
});

test('every adjustment of a run is balanced and the translation difference closes each member', function (): void {
    $run = conRun(conBuildGroup())->assertCreated()->json('data');

    foreach ($run['adjustments'] as $adjustment) {
        $debit = '0';
        $credit = '0';

        foreach ($adjustment['lines'] as $line) {
            $debit = bcadd($debit, $line['debit'], 4);
            $credit = bcadd($credit, $line['credit'], 4);
        }

        if ($adjustment['adjustment_type'] !== 'translation_difference') {
            expect(bccomp($debit, $credit, 4))->toBe(0);
        }
    }

    $translation = collect($run['adjustments'])->firstWhere('adjustment_type', 'translation_difference');
    expect($translation['member_tenant_id'])->toBe($this->svizzera->id)
        ->and($translation['lines'][0]['credit'])->toBe('5400.0000')
        ->and($translation['lines'][0]['account_code'])->toBe('CONS-CTA');

    $investment = collect($run['adjustments'])->first(fn (array $adjustment): bool => $adjustment['adjustment_type'] === 'investment_equity' && $adjustment['member_tenant_id'] === $this->svizzera->id);
    expect(collect($investment['lines'])->firstWhere('account_code', 'CONS-DIFF')['debit'])->toBe('5000.0000');
});

test('a run is reproducible, verifiable, lockable and audit-logged', function (): void {
    $group = conBuildGroup();

    $first = conRun($group)->assertCreated()->json('data');
    $second = conRun($group)->assertCreated()->json('data');

    expect($second['version'])->toBe(2)
        ->and($second['input_fingerprint'])->toBe($first['input_fingerprint'])
        ->and($second['totals'])->toBe($first['totals'])
        ->and(conTrialBalance($second['id']))->toBe(conTrialBalance($first['id']));

    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$first['id']}/verify")->assertOk()->assertJsonPath('data.matches', true);

    // a ledger of a member changes after the run: the fingerprint no longer matches and the run cannot be locked
    conPost($this->svizzera, '2026-05-20', $this->svizzeraChart['bank'], $this->svizzeraChart['sales'], '100');
    conActAs($this->accountant, $this->parent);

    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$first['id']}/verify")->assertOk()->assertJsonPath('data.matches', false);
    test()->postJson("/api/v1/general-ledger/consolidation/runs/{$first['id']}/lock")->assertStatus(409);

    $third = conRun($group)->assertCreated()->json('data');
    expect($third['input_fingerprint'])->not->toBe($first['input_fingerprint']);

    test()->postJson("/api/v1/general-ledger/consolidation/runs/{$third['id']}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
    test()->postJson("/api/v1/general-ledger/consolidation/runs/{$third['id']}/lock")->assertStatus(409);
    test()->deleteJson("/api/v1/general-ledger/consolidation/runs/{$third['id']}")->assertStatus(409);

    // one locked run per period
    $fourth = conRun($group)->assertCreated()->json('data');
    test()->postJson("/api/v1/general-ledger/consolidation/runs/{$fourth['id']}/lock")->assertStatus(409);

    test()->deleteJson("/api/v1/general-ledger/consolidation/runs/{$first['id']}")->assertOk();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$first['id']}")->assertNotFound();

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->parent->id)->pluck('action')->all();
    expect($actions)->toContain('CONSOLIDATION_RUN_CREATED')
        ->and($actions)->toContain('CONSOLIDATION_RUN_LOCKED')
        ->and($actions)->toContain('CONSOLIDATION_RUN_DELETED');
});

test('the equity method brings the investment to the share of net assets and shows the share of profit', function (): void {
    $associate = Tenant::create(['name' => 'Collegata', 'legal_name' => 'Collegata Srl', 'default_currency' => 'EUR']);
    conGrant($this->accountant, $associate, 'accountant');
    $a = conChart($associate);

    $holding = conAccount($this->parent, '1520', 'asset', 'debit');
    conPost($this->parent, '2026-02-01', $holding, $this->parentChart['bank'], '30000');
    conPost($associate, '2026-01-02', $a['bank'], $a['capital'], '100000');
    conPost($associate, '2026-03-01', $a['bank'], $a['sales'], '20000');
    conPost($associate, '2026-03-02', $a['cost'], $a['bank'], '5000');

    conActAs($this->accountant, $this->parent);
    $group = (string) conPostJson('/api/v1/general-ledger/consolidation/groups', ['name' => 'Gruppo Equity', 'group_currency' => 'EUR'])->json('data.id');
    conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/members", ['member_tenant_id' => $associate->id, 'ownership_percentage' => 30, 'consolidation_method' => 'equity'])->assertCreated();
    $relation = (string) conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/relations", ['entity_a_tenant_id' => $this->parent->id, 'entity_b_tenant_id' => $associate->id])->json('data.id');
    conPostJson("/api/v1/general-ledger/consolidation/relations/{$relation}/mappings", ['mapping_type' => 'investment_equity', 'account_a_id' => $holding->id, 'account_b_id' => null])->assertCreated();

    $run = conRun($group)->assertCreated()->json('data');
    $tb = conTrialBalance($run['id']);

    // 30 % of net assets 115 000 = 34 500: cost 30 000 plus 4 500, all of it the share of the profit 15 000
    expect($tb['1520']['net'])->toBe('34500.0000')
        ->and($tb['CONS-EQM-PL']['net'])->toBe('-4500.0000')
        ->and($tb)->not->toHaveKey('CONS-EQM-RES')
        ->and($tb['3000']['net'])->toBe('-200000.0000')
        ->and($run['totals']['trial_balance_is_balanced'])->toBeTrue()
        ->and($run['totals']['balance_sheet_is_balanced'])->toBeTrue();

    // the associate is not added line by line: its revenue is nowhere in the consolidated accounts
    expect($tb['4000']['net'])->toBe('-20000.0000');
});

test('a proportional member refuses the run', function (): void {
    $group = conBuildGroup();
    $memberId = collect(test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.members'))->firstWhere('member_tenant_id', $this->italia->id)['id'];
    test()->putJson("/api/v1/general-ledger/consolidation/groups/{$group}/members/{$memberId}", ['ownership_percentage' => 50, 'consolidation_method' => 'proportional'])->assertOk();

    conRun($group)->assertStatus(422);

    expect(ConsolidationRun::withoutGlobalScopes()->count())->toBe(0);
});

test('a missing exchange rate stops the run without storing anything', function (): void {
    $group = conBuildGroup();
    ExchangeRate::query()->delete();

    conRun($group)->assertStatus(422)->assertJsonPath('success', false);

    expect(ConsolidationRun::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('gl_consolidation_run_balances')->count())->toBe(0);
});

test('a consolidated statement can be put in a reclassification scheme of the parent', function (): void {
    $group = conBuildGroup();
    $run = conRun($group)->assertCreated()->json('data');

    app(TenantContext::class)->setTenant($this->parent);
    $scheme = StatementScheme::create(['name' => 'Gruppo SP', 'statement_type' => 'balance_sheet', 'is_active' => true]);
    StatementSchemeRow::create(['scheme_id' => $scheme->id, 'code' => 'ASSETS', 'label' => 'Assets', 'sort_order' => 1, 'row_type' => 'accounts', 'sign' => 1, 'account_prefixes' => ['1', 'CONS-DIFF']]);
    StatementSchemeRow::create(['scheme_id' => $scheme->id, 'code' => 'LIABILITIES', 'label' => 'Liabilities', 'sort_order' => 2, 'row_type' => 'accounts', 'sign' => 1, 'account_prefixes' => ['2']]);
    StatementSchemeRow::create(['scheme_id' => $scheme->id, 'code' => 'EQUITY', 'label' => 'Equity', 'sort_order' => 3, 'row_type' => 'accounts', 'sign' => 1, 'account_prefixes' => ['3', 'CONS-CTA', 'CONS-RESULT']]);
    app(TenantContext::class)->clear();
    conActAs($this->accountant, $this->parent);

    $data = test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/reclassified?scheme_id={$scheme->id}")->assertOk()->json('data');
    $rows = collect($data['rows'])->keyBy('code');

    expect($rows['ASSETS']['amount'])->toBe('281000.0000')
        ->and($rows['EQUITY']['amount'])->toBe('281000.0000')
        ->and($data['unmapped_accounts'])->toBe([]);
});

test('a user who cannot read every member entity can neither add it, run, reconcile nor read a run', function (): void {
    $group = conBuildGroup();
    $run = conRun($group)->assertCreated()->json('data');

    $limited = conMember($this->parent, 'accountant');
    conGrant($limited, $this->italia, 'accountant');
    conActAs($limited, $this->parent);

    // Svizzera is out of reach of this user: the whole request is refused, never a partial consolidation
    conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/runs", ['fiscal_period_id' => $this->juneParent])->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}/intercompany-reconciliation?fiscal_period_id={$this->juneParent}")->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}/runs")->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}")->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/trial-balance")->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/income-statement")->assertForbidden();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/verify")->assertForbidden();
    test()->postJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/lock")->assertForbidden();
    test()->deleteJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}")->assertForbidden();

    // an entity the user cannot read cannot be pulled into a group either
    conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/members", ['member_tenant_id' => $this->outsider->id, 'ownership_percentage' => 100, 'consolidation_method' => 'full'])->assertForbidden();

    expect(ConsolidationRun::withoutGlobalScopes()->where('version', '>', 1)->count())->toBe(0);
});

test('the data of an entity outside the group never appears in a run', function (): void {
    $group = conBuildGroup();
    $run = conRun($group)->assertCreated()->json('data');

    $tb = conTrialBalance($run['id']);
    $everything = json_encode([$run, $tb, test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->json(), test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/income-statement")->json()], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain('777777')
        ->and($everything)->not->toContain((string) $this->outsider->id)
        ->and($tb['4000']['net'])->toBe('-60500.0000')
        ->and(DB::table('gl_consolidation_run_balances')->where('member_tenant_id', $this->outsider->id)->count())->toBe(0);

    $memberIds = collect($run['members'])->pluck('tenant_id')->all();
    expect($memberIds)->toEqualCanonicalizing([$this->parent->id, $this->italia->id, $this->svizzera->id]);
});

test('another tenant sees no group, run or mapping of the owner', function (): void {
    $group = conBuildGroup();
    $run = conRun($group)->assertCreated()->json('data');
    $relation = (string) test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->json('data.relations.0.id');

    // the outsider is a full accountant of its own tenant and a member of the group's tenants, but the group is not its own
    $other = conMember($this->outsider, 'owner');
    conGrant($other, $this->parent, 'owner');
    conGrant($other, $this->italia, 'owner');
    conGrant($other, $this->svizzera, 'owner');
    conActAs($other, $this->outsider);

    expect(test()->getJson('/api/v1/general-ledger/consolidation/groups')->assertOk()->json('data'))->toBe([]);
    test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->assertNotFound();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}")->assertNotFound();
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->assertNotFound();
    test()->deleteJson("/api/v1/general-ledger/consolidation/relations/{$relation}")->assertNotFound();
    test()->postJson("/api/v1/general-ledger/consolidation/groups/{$group}/runs", ['fiscal_period_id' => conPeriodId($this->outsider, '2026-06-30')])->assertNotFound();

    foreach (['gl_consolidation_groups', 'gl_consolidation_members', 'gl_intercompany_relations', 'gl_intercompany_account_mappings', 'gl_consolidation_runs', 'gl_consolidation_run_balances', 'gl_consolidation_adjustments', 'gl_consolidation_adjustment_lines'] as $table) {
        expect(DB::table($table)->where('tenant_id', '!=', $this->parent->id)->count())->toBe(0, $table);
    }
});

test('losing the access to a member closes the stored results as well', function (): void {
    $group = conBuildGroup();
    $run = conRun($group)->assertCreated()->json('data');

    $reader = conMember($this->parent, 'accountant_readonly');
    conGrant($reader, $this->italia, 'accountant_readonly');
    conGrant($reader, $this->svizzera, 'accountant_readonly');
    conActAs($reader, $this->parent);

    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->assertOk();
    conPostJson("/api/v1/general-ledger/consolidation/groups/{$group}/runs", ['fiscal_period_id' => $this->juneParent])->assertForbidden();

    $reader->tenants()->detach($this->svizzera->id);
    conActAs($reader, $this->parent);
    test()->getJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}/balance-sheet")->assertForbidden();
});

test('the access rules of the ledger module apply to the consolidation configuration', function (): void {
    $viewer = conMember($this->parent, 'sales');
    conActAs($viewer, $this->parent);

    test()->getJson('/api/v1/general-ledger/consolidation/groups')->assertForbidden();
    conPostJson('/api/v1/general-ledger/consolidation/groups', ['name' => 'Nope', 'group_currency' => 'EUR'])->assertForbidden();

    $reader = conMember($this->parent, 'accountant_readonly');
    conActAs($reader, $this->parent);
    test()->getJson('/api/v1/general-ledger/consolidation/groups')->assertOk();
    conPostJson('/api/v1/general-ledger/consolidation/groups', ['name' => 'Nope', 'group_currency' => 'EUR'])->assertForbidden();
});

test('api keys are kept out of the consolidation endpoints', function (): void {
    $request = Request::create('/api/v1/general-ledger/consolidation/groups', 'GET');
    $request->attributes->set('api_key_scopes', ['ledger.read']);

    $response = (new RejectApiKeyAccess)->handle($request, static fn (): Response => response('reached'));

    expect($response->getStatusCode())->toBe(403);

    $plain = Request::create('/api/v1/general-ledger/consolidation/groups', 'GET');
    expect((new RejectApiKeyAccess)->handle($plain, static fn (): Response => response('reached'))->getContent())->toBe('reached');
});

test('the factories of the consolidation configuration build rows of the owner tenant', function (): void {
    $group = ConsolidationGroup::factory()->create();
    $member = ConsolidationMember::factory()->create(['group_id' => $group->id]);
    $relation = IntercompanyRelation::factory()->create(['group_id' => $group->id]);

    expect($member->tenant_id)->toBe($group->tenant_id)
        ->and($relation->tenant_id)->toBe($group->tenant_id)
        ->and($member->consolidation_method)->toBe(ConsolidationMethod::Full);
});

test('a group with runs cannot be deleted and an empty one can', function (): void {
    $group = conBuildGroup();
    $run = conRun($group)->assertCreated()->json('data');

    test()->deleteJson("/api/v1/general-ledger/consolidation/groups/{$group}")->assertStatus(409);
    test()->deleteJson("/api/v1/general-ledger/consolidation/runs/{$run['id']}")->assertOk();
    test()->deleteJson("/api/v1/general-ledger/consolidation/groups/{$group}")->assertOk();
    test()->getJson("/api/v1/general-ledger/consolidation/groups/{$group}")->assertNotFound();
});
