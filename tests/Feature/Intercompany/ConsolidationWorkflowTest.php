<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

require_once __DIR__.'/IntercompanySupport.php';

uses(RefreshDatabase::class);

/**
 * The guided consolidation on top of the ledger consolidation: period rates, the workflow with its optimistic version and
 * reopening, the elimination ledger with manual adjustments, the schedules, the statements in the three standards, the cash
 * flow and the package. Scenario (June 2027, group currency EUR): the parent owns 80 % of Italia (EUR) and 100 % of Svizzera
 * (CHF, rate 0.95 on 1 January and 1.05 on 30 June). The parent sold goods for 12 000 (cost 9 000) to Italia, half still in
 * stock, which leaves 1 500 of unrealised profit to eliminate.
 */
beforeEach(function (): void {
    $this->parent = icTenant('Capogruppo');
    $this->italia = icTenant('Italia');
    $this->svizzera = icTenant('Svizzera', 'CHF');
    $this->outsider = icTenant('Estranea');

    $this->accountant = icUser($this->parent);
    icGrant($this->accountant, $this->italia);
    icGrant($this->accountant, $this->svizzera);
    $this->outsiderAccountant = icUser($this->outsider);

    $this->p = icChart($this->parent);
    $this->i = icChart($this->italia);
    $this->s = icChart($this->svizzera);
    icRates();

    icPost($this->parent, '2027-01-02', $this->p['bank'], $this->p['capital'], '200000');
    icPost($this->parent, '2027-03-12', $this->p['bank'], $this->p['sales'], '20000');
    icPost($this->parent, '2027-03-15', $this->p['cost'], $this->p['bank'], '5000');
    icPost($this->italia, '2027-01-02', $this->i['bank'], $this->i['capital'], '100000');
    icPost($this->italia, '2027-03-01', $this->i['inventory'], $this->i['bank'], '12000');
    icPost($this->italia, '2027-03-12', $this->i['bank'], $this->i['sales'], '30000');
    icPost($this->svizzera, '2027-01-02', $this->s['bank'], $this->s['capital'], '50000');
    icPost($this->svizzera, '2027-05-12', $this->s['bank'], $this->s['sales'], '10000');

    $this->periodId = (string) FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->parent->id)->whereDate('start_date', '<=', '2027-06-30')->whereDate('end_date', '>=', '2027-06-30')->firstOrFail()->id;

    icActAs($this->accountant, $this->parent);

    $gl = '/api/v1/general-ledger/consolidation';
    $this->groupId = (string) $this->postJson("{$gl}/groups", ['name' => 'Gruppo Alfa', 'group_currency' => 'EUR'])->assertCreated()->json('data.id');
    $this->postJson("{$gl}/groups/{$this->groupId}/members", ['member_tenant_id' => $this->italia->id, 'ownership_percentage' => 80, 'consolidation_method' => 'full'])->assertCreated();
    $this->postJson("{$gl}/groups/{$this->groupId}/members", ['member_tenant_id' => $this->svizzera->id, 'ownership_percentage' => 100, 'consolidation_method' => 'full'])->assertCreated();
    $this->postJson("{$gl}/groups/{$this->groupId}/intragroup-transactions", [
        'seller_tenant_id' => $this->parent->id, 'buyer_tenant_id' => $this->italia->id, 'asset_type' => 'inventory', 'transaction_date' => '2027-03-01',
        'sale_price' => 12000, 'seller_cost' => 9000, 'remaining_percentage' => 50, 'asset_account_id' => $this->i['inventory']->id, 'profit_account_id' => $this->p['sales']->id,
    ])->assertCreated();
});

afterEach(fn () => app(TenantContext::class)->clear());

function icRun(): string
{
    $test = test();
    icActAs($test->accountant, $test->parent);

    return (string) $test->postJson("/api/v1/general-ledger/consolidation/groups/{$test->groupId}/runs", ['fiscal_period_id' => $test->periodId])->assertCreated()->json('data.id');
}

/**
 * Moves a fresh run to `review` through the workflow and returns its version.
 */
function icToReview(string $run): int
{
    $test = test();
    $version = (int) $test->getJson("/api/v1/intercompany/runs/{$run}/workflow")->assertOk()->json('data.version');
    $version = (int) $test->postJson("/api/v1/intercompany/runs/{$run}/workflow/advance", ['to' => 'calculating', 'expected_version' => $version])->assertOk()->json('data.version');

    return (int) $test->postJson("/api/v1/intercompany/runs/{$run}/workflow/advance", ['to' => 'review', 'expected_version' => $version])->assertOk()->json('data.version');
}

test('a rate fixed for the period wins over the published exchange rate', function (): void {
    $this->postJson("/api/v1/intercompany/groups/{$this->groupId}/period-rates", ['currency' => 'CHF', 'rate_type' => 'closing', 'period_end' => '2027-06-30', 'rate' => 2])
        ->assertCreated()
        ->assertJsonPath('data.rate', '2.00000000');

    $run = ConsolidationRun::withoutGlobalScopes()->findOrFail(icRun());

    expect(json_encode($run->rates))->toContain('2.000000');

    $this->getJson("/api/v1/intercompany/groups/{$this->groupId}/period-rates?currency=CHF")->assertOk()->assertJsonCount(1, 'data');
});

test('a period rate for the group currency or with a zero rate is refused', function (): void {
    $this->postJson("/api/v1/intercompany/groups/{$this->groupId}/period-rates", ['currency' => 'EUR', 'rate_type' => 'closing', 'period_end' => '2027-06-30', 'rate' => 1])->assertStatus(422);
    $this->postJson("/api/v1/intercompany/groups/{$this->groupId}/period-rates", ['currency' => 'CHF', 'rate_type' => 'average', 'period_end' => '2027-06-30', 'rate' => 0])->assertStatus(422);
});

test('the scope validation checks the tree, the currencies, the rates and the balances', function (): void {
    $checks = $this->getJson("/api/v1/intercompany/groups/{$this->groupId}/scope-validation?fiscal_period_id={$this->periodId}")->assertOk()->json('data');

    expect($checks['checks'])->not->toBeEmpty()
        ->and(collect($checks['checks'])->where('code', 'has_parent')->first()['passed'])->toBeTrue()
        ->and(collect($checks['checks'])->where('code', 'rate_available')->every(fn (array $check): bool => $check['passed']))->toBeTrue();
});

test('the workflow moves step by step and refuses a stale version or a skipped step', function (): void {
    $run = icRun();

    $this->getJson("/api/v1/intercompany/runs/{$run}/workflow")->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 1)->assertJsonPath('data.allowed_transitions', ['calculating']);

    $this->postJson("/api/v1/intercompany/runs/{$run}/workflow/advance", ['to' => 'review', 'expected_version' => 1])->assertStatus(409)->assertJsonPath('error_code', 'intercompany.error.illegal_transition');
    $this->postJson("/api/v1/intercompany/runs/{$run}/workflow/advance", ['to' => 'calculating', 'expected_version' => 7])->assertStatus(409)->assertJsonPath('error_code', 'intercompany.error.stale_version');

    $version = icToReview($run);
    expect($version)->toBe(3);

    $this->postJson("/api/v1/intercompany/runs/{$run}/workflow/advance", ['to' => 'locked', 'expected_version' => $version])
        ->assertOk()
        ->assertJsonPath('data.status', 'locked');

    expect(ConsolidationRun::withoutGlobalScopes()->findOrFail($run)->isLocked())->toBeTrue();
});

test('a locked run refuses manual adjustments and is reopened only with a reason, which is kept in the history', function (): void {
    $run = icRun();
    $version = icToReview($run);
    $locked = (int) $this->postJson("/api/v1/intercompany/runs/{$run}/workflow/advance", ['to' => 'locked', 'expected_version' => $version])->assertOk()->json('data.version');

    $adjustment = ['reference' => 'AUD-1', 'reason' => 'Audit correction of the stock', 'lines' => [
        ['account_code' => '6000', 'account_type' => 'expense', 'debit' => 100],
        ['account_code' => '1300', 'account_type' => 'asset', 'credit' => 100],
    ]];

    $this->postJson("/api/v1/intercompany/runs/{$run}/manual-adjustments", $adjustment)->assertStatus(409)->assertJsonPath('error_code', 'intercompany.error.run_locked');
    $this->postJson("/api/v1/intercompany/runs/{$run}/reopen", ['reason' => 'short', 'expected_version' => $locked])->assertStatus(422);
    $this->postJson("/api/v1/intercompany/runs/{$run}/reopen", ['reason' => 'The auditor asked for a correction', 'expected_version' => $locked - 1])->assertStatus(409);

    $this->postJson("/api/v1/intercompany/runs/{$run}/reopen", ['reason' => 'The auditor asked for a correction', 'expected_version' => $locked])
        ->assertOk()
        ->assertJsonPath('data.status', 'review');

    expect(ConsolidationRun::withoutGlobalScopes()->findOrFail($run)->isLocked())->toBeFalse();

    $this->postJson("/api/v1/intercompany/runs/{$run}/manual-adjustments", $adjustment)->assertCreated();

    $history = $this->getJson("/api/v1/intercompany/runs/{$run}/workflow")->assertOk()->json('data.history');
    $reopen = collect($history)->firstWhere('action', 'reopen');

    expect($reopen['reason'])->toBe('The auditor asked for a correction')->and($reopen['from_status'])->toBe('locked')->and(count($history))->toBe(4);
});

test('a reopened run can only be reopened when it is locked', function (): void {
    $run = icRun();
    $version = (int) $this->getJson("/api/v1/intercompany/runs/{$run}/workflow")->json('data.version');

    $this->postJson("/api/v1/intercompany/runs/{$run}/reopen", ['reason' => 'Nothing is locked yet', 'expected_version' => $version])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'intercompany.error.run_not_locked');
});

test('the elimination ledger shows every line with its amounts before and after and includes manual adjustments', function (): void {
    $run = icRun();

    $ledger = $this->getJson("/api/v1/intercompany/runs/{$run}/elimination-ledger")->assertOk()->json('data');
    $unrealised = collect($ledger['entries'])->firstWhere('elimination_type', 'unrealised_profit');

    expect($unrealised)->not->toBeNull()
        ->and($unrealised['amount'])->toBe('1500.0000')
        ->and($unrealised['lines'])->not->toBeEmpty()
        ->and($unrealised['lines'][0])->toHaveKeys(['account_code', 'debit', 'credit', 'pre_elimination_amount', 'post_elimination_amount'])
        ->and($ledger['totals_by_type'])->toHaveKey('unrealised_profit');

    $manual = $this->postJson("/api/v1/intercompany/runs/{$run}/manual-adjustments", ['reference' => 'MAN-1', 'reason' => 'Rounding of the translation', 'lines' => [
        ['account_code' => '6000', 'account_type' => 'expense', 'debit' => 50.5],
        ['account_code' => '1000', 'account_type' => 'asset', 'credit' => 50.5],
    ]])->assertCreated()->json('data');

    $after = $this->getJson("/api/v1/intercompany/runs/{$run}/elimination-ledger?type=manual")->assertOk()->json('data');

    expect($after['entries'])->toHaveCount(1)->and($after['entries'][0]['reference'])->toBe('MAN-1')->and($after['entries'][0]['amount'])->toBe('50.5000');

    $this->deleteJson("/api/v1/intercompany/manual-adjustments/{$manual['id']}")->assertOk();
    $this->getJson("/api/v1/intercompany/runs/{$run}/elimination-ledger?type=manual")->assertOk()->assertJsonCount(0, 'data.entries');
});

test('an unbalanced manual adjustment is refused', function (): void {
    $run = icRun();

    $this->postJson("/api/v1/intercompany/runs/{$run}/manual-adjustments", ['reference' => 'MAN-2', 'reason' => 'Does not balance at all', 'lines' => [
        ['account_code' => '6000', 'account_type' => 'expense', 'debit' => 100],
        ['account_code' => '1000', 'account_type' => 'asset', 'credit' => 90],
    ]])->assertStatus(422)->assertJsonPath('error_code', 'intercompany.error.adjustment_unbalanced');

    $this->postJson("/api/v1/intercompany/runs/{$run}/manual-adjustments", ['reference' => '', 'reason' => 'x', 'lines' => []])->assertStatus(422);
});

test('the schedule of non-controlling interests and the schedule of shareholdings come from the run', function (): void {
    $run = icRun();

    $nci = $this->getJson("/api/v1/intercompany/runs/{$run}/nci-schedule")->assertOk()->json('data');
    $italia = collect($nci['rows'])->firstWhere('member_tenant_id', $this->italia->id);

    expect($italia['nci_percentage'])->toBe('20.0000')
        ->and($italia['nci_movement'])->toBe($italia['nci_profit'])
        ->and(bcsub($italia['nci_net_assets'], $italia['nci_profit'], 4))->toBe($italia['nci_opening']);

    $holdings = $this->getJson("/api/v1/intercompany/runs/{$run}/shareholdings")->assertOk()->json('data.rows');
    $rows = collect($holdings)->keyBy('member_tenant_id');

    expect($holdings)->toHaveCount(3)
        ->and($rows[$this->italia->id]['direct_percentage'])->toBe('80.0000')
        ->and($rows[$this->italia->id]['effective_percentage'])->toBe('80.0000')
        ->and($rows[$this->italia->id]['voting_percentage'])->toBe('80.0000')
        ->and($rows[$this->svizzera->id]['currency'])->toBe('CHF')
        ->and($rows[$this->svizzera->id]['consolidation_method'])->toBe('full');
});

test('the statements follow the chosen standard and every line adds up from standalone and eliminations', function (): void {
    $run = icRun();

    $fer = $this->getJson("/api/v1/intercompany/runs/{$run}/statements?standard=fer30")->assertOk()->json('data');
    $ifrs = $this->getJson("/api/v1/intercompany/runs/{$run}/statements?standard=ifrs10")->assertOk()->json('data');
    $oic = $this->getJson("/api/v1/intercompany/runs/{$run}/statements?standard=oic17")->assertOk()->json('data');

    expect($fer['presentation']['framework'])->toBe('Swiss GAAP FER 30')
        ->and(array_column($fer['balance_sheet']['sections'], 'code'))->toBe(['assets', 'liabilities', 'equity'])
        ->and(array_column($ifrs['balance_sheet']['sections'], 'code'))->toBe(['assets', 'equity', 'liabilities'])
        ->and($ifrs['income_statement']['layout'])->toBe('function')
        ->and(array_column($ifrs['income_statement']['sections'], 'code'))->toBe(['revenue', 'cogs', 'operating_expenses'])
        ->and($oic['income_statement']['layout'])->toBe('nature')
        ->and(array_column($oic['income_statement']['sections'], 'code'))->toBe(['value_of_production', 'cost_of_production'])
        ->and($oic['presentation']['goodwill_treatment'])->toBe('capitalise_amortise')
        ->and($fer['presentation']['extraordinary_items'])->toBeTrue()
        ->and($ifrs['presentation']['extraordinary_items'])->toBeFalse();

    foreach ([$fer, $ifrs, $oic] as $statements) {
        foreach (['balance_sheet', 'income_statement'] as $statement) {
            foreach ($statements[$statement]['sections'] as $section) {
                foreach ($section['lines'] as $line) {
                    expect(bcadd($line['standalone'], $line['eliminations'], 4))->toBe($line['consolidated']);
                }
            }
        }
    }

    // The unrealised profit lowers the stock: 12 000 held by Italia, half of the 3 000 margin eliminated.
    $assets = collect($fer['balance_sheet']['sections'])->firstWhere('code', 'assets');
    $stock = collect($assets['lines'])->firstWhere('account_code', '1300');

    expect(bcsub($stock['standalone'], $stock['consolidated'], 4))->toBe('1500.0000');

    $this->getJson("/api/v1/intercompany/runs/{$run}/statements?standard=gaap")->assertStatus(422);
    $this->getJson("/api/v1/intercompany/runs/{$run}/statements?standard=oic17&statement=balance_sheet")->assertOk()->assertJsonMissingPath('data.income_statement');
});

test('the consolidated cash flow reconciles with the change of cash', function (): void {
    $run = icRun();

    $cash = $this->getJson("/api/v1/intercompany/runs/{$run}/cash-flow")->assertOk()->json('data');

    expect($cash['method'])->toBe('indirect')
        ->and($cash['opening_available'])->toBeFalse()
        ->and($cash['opening_cash'])->toBe('0.0000')
        ->and(bcadd(bcadd($cash['operating'], $cash['investing'], 4), bcadd($cash['financing'], $cash['fx_effect'], 4), 4))->toBe($cash['net_change'])
        ->and(bcsub(bcsub($cash['closing_cash'], $cash['opening_cash'], 4), $cash['net_change'], 4))->toBe($cash['unreconciled'])
        ->and(collect($cash['lines'])->pluck('code')->all())->toContain('result_of_the_period', 'net_investment', 'translation_effect');
});

test('the package carries every schedule and downloads as json, csv and xlsx', function (): void {
    $run = icRun();

    $package = $this->postJson("/api/v1/intercompany/runs/{$run}/packages", ['standard' => 'ifrs10'])->assertCreated()->json('data');

    expect($package['standard'])->toBe('ifrs10')->and(strlen($package['checksum']))->toBe(64);

    $shown = $this->getJson("/api/v1/intercompany/packages/{$package['id']}")->assertOk()->json('data');

    expect($shown['sections'])->toContain('balance_sheet', 'income_statement', 'cash_flow', 'shareholdings', 'nci_schedule', 'reciprocal_reconciliation', 'elimination_ledger')
        ->and($shown['payload']['meta']['standard'])->toBe('ifrs10')
        ->and($shown['payload']['meta']['run_id'])->toBe($run);

    $this->getJson("/api/v1/intercompany/runs/{$run}/packages")->assertOk()->assertJsonCount(1, 'data');

    $this->get("/api/v1/intercompany/packages/{$package['id']}/download?format=json")->assertOk()->assertHeader('content-type', 'application/json');
    $csv = $this->get("/api/v1/intercompany/packages/{$package['id']}/download?format=csv")->assertOk();
    expect($csv->getContent())->toContain('#balance_sheet', '#shareholdings', '#elimination_ledger');

    $xlsx = $this->get("/api/v1/intercompany/packages/{$package['id']}/download?format=xlsx")->assertOk();
    expect($xlsx->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
});

test('a user of another tenant cannot reach a run or its package', function (): void {
    $run = icRun();
    $package = $this->postJson("/api/v1/intercompany/runs/{$run}/packages", ['standard' => 'fer30'])->assertCreated()->json('data.id');

    icActAs($this->outsiderAccountant, $this->outsider);

    $this->getJson("/api/v1/intercompany/runs/{$run}/workflow")->assertNotFound();
    $this->getJson("/api/v1/intercompany/runs/{$run}/statements")->assertNotFound();
    $this->getJson("/api/v1/intercompany/packages/{$package}")->assertNotFound();
});

test('lookups return enum codes only', function (): void {
    $lookups = $this->getJson('/api/v1/intercompany/lookups')->assertOk()->json('data');

    expect($lookups['enums']['standard'])->toBe(['fer30', 'ifrs10', 'oic17'])
        ->and($lookups['enums']['anomalyType'])->toContain('timing', 'fx', 'amount', 'tax', 'missing_counterpart')
        ->and($lookups['enums']['eliminationType'])->toContain('unrealised_profit', 'manual')
        ->and(collect($lookups['standards'])->pluck('code')->all())->toBe(['fer30', 'ifrs10', 'oic17']);
});
