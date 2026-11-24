<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Associations\Models\LegalParameter;
use Modules\Associations\Models\StatementMapping;
use Modules\Associations\Services\AssociationLedgerPoster;
use Modules\Associations\Services\LegalParameterService;
use Modules\Associations\Support\ThirdSectorScheme;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Services\LedgerAccountResolver;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    associationsSetUp($this);
    openFiscalPeriod($this->tenant);
    $this->year = (int) now()->year;
    $account = static fn (string $code, string $name, string $type, string $balance): ChartOfAccount => ChartOfAccount::query()->create(['account_code' => $code, 'name' => $name, 'account_class' => $type, 'account_type' => $type, 'normal_balance' => $balance]);
    $this->fees = $account('7010', 'Membership fees', 'revenue', 'credit');
    $this->bar = $account('7500', 'Bar sales', 'revenue', 'credit');
    $this->services = $account('6100', 'Services', 'expense', 'debit');
    $this->cash = app(LedgerAccountResolver::class)->cash($this->tenant->id);
    $this->barCentre = CostCenter::query()->create(['code' => 'BAR', 'name' => 'Bar']);

    $poster = app(AssociationLedgerPoster::class);
    $today = CarbonImmutable::today();
    $poster->postInflow($this->fees->id, '1000.00', $today, 'cash', 'Fees', 'test', (string) Str::uuid7(), [], null);
    $poster->postOutflow($this->services->id, '300.00', $today, 'cash', 'Cleaning', 'test', (string) Str::uuid7(), [], null);
    $poster->postInflow($this->bar->id, '200.00', $today, 'cash', 'Bar', 'test', (string) Str::uuid7(), ['cost_center_id' => $this->barCentre->id], null);
});

afterEach(fn () => associationsTearDown());

function statementsMap(object $test, string $scheme, string $line, string $accountId, array $extra = []): void
{
    $test->postJson('/api/v1/associations/statements/mappings', ['scheme' => $scheme, 'line_code' => $line, 'account_id' => $accountId, ...$extra])->assertCreated();
}

function statementsMapAll(object $test): void
{
    statementsMap($test, 'A', 'A-AS.C.IV.3', $test->cash->id);
    statementsMap($test, 'B', 'B-R.A.1', $test->fees->id);
    statementsMap($test, 'B', 'B-C.A.2', $test->services->id);
    statementsMap($test, 'B', 'B-R.B.3', $test->bar->id, ['cost_center_id' => $test->barCentre->id, 'is_commercial' => true]);
    statementsMap($test, 'D', 'D-I.A.1', $test->fees->id);
    statementsMap($test, 'D', 'D-O.A.2', $test->services->id);
    statementsMap($test, 'D', 'D-I.B.3', $test->bar->id);
}

test('the schemes are those of the decree, and a mapping must point at a line of the scheme', function (): void {
    actAs($this, 'accountant');
    $schemes = $this->getJson('/api/v1/associations/statements/schemes')->assertOk()->json('data');

    expect($schemes['source'])->toContain('5 marzo 2020')
        ->and($schemes['A'])->toHaveKey('A-AS.C.II.10')
        ->and($schemes['B'])->toHaveKey('B-R.A.5')
        ->and($schemes['D'])->toHaveKey('D-O.INV.4')
        ->and($schemes['C'])->toHaveCount(24);

    $this->postJson('/api/v1/associations/statements/mappings', ['scheme' => 'B', 'line_code' => 'A-AS.C.IV.3', 'account_id' => $this->fees->id])->assertUnprocessable();
    $this->postJson('/api/v1/associations/statements/mappings', ['scheme' => 'B', 'line_code' => ThirdSectorScheme::ResultLine, 'account_id' => $this->fees->id])->assertUnprocessable();
    statementsMap($this, 'B', 'B-R.A.1', $this->fees->id);
    $this->postJson('/api/v1/associations/statements/mappings', ['scheme' => 'B', 'line_code' => 'B-R.A.10', 'account_id' => $this->fees->id])->assertUnprocessable();
});

test('the management report, the balance sheet and the cash report come from the ledger through the mapping', function (): void {
    actAs($this, 'accountant');
    statementsMapAll($this);

    $management = $this->getJson("/api/v1/associations/statements/{$this->year}/management-report")->assertOk()->json('data');
    $lines = collect($management['lines'])->keyBy('code');

    expect($lines['B-R.A.1']['current'])->toBe('1000.00')
        ->and($lines['B-C.A.2']['current'])->toBe('300.00')
        ->and($lines['B-R.B.3']['current'])->toBe('200.00')
        ->and($management['sections']['A']['result'])->toBe('700.00')
        ->and($management['totals']['result'])->toBe('900.00')
        ->and($management['unmapped'])->toBe([]);

    $balance = $this->getJson("/api/v1/associations/statements/{$this->year}/balance-sheet")->assertOk()->json('data');
    $balanceLines = collect($balance['lines'])->keyBy('code');

    expect($balanceLines['A-AS.C.IV.3']['current'])->toBe('900.00')
        ->and($balanceLines[ThirdSectorScheme::ResultLine]['current'])->toBe('900.00')
        ->and($balance['totals']['difference'])->toBe('0.00');

    $cash = $this->getJson("/api/v1/associations/statements/{$this->year}/cash-report")->assertOk()->json('data');

    expect($cash['totals']['inflows'])->toBe('1200.00')
        ->and($cash['totals']['outflows'])->toBe('300.00')
        ->and($cash['totals']['closing_cash'])->toBe('900.00')
        ->and($cash['totals']['reconciliation_difference'])->toBe('0.00');

    $bar = $this->getJson("/api/v1/associations/statements/{$this->year}/management-report?cost_center_ids={$this->barCentre->id}")->json('data');
    expect($bar['totals']['revenues'])->toBe('200.00')->and($bar['totals']['costs'])->toBe('0.00');
});

test('an unmapped account blocks the filing, and a complete mapping lets it be prepared, frozen and marked as filed', function (): void {
    actAs($this, 'admin');
    statementsMap($this, 'A', 'A-AS.C.IV.3', $this->cash->id);
    statementsMap($this, 'B', 'B-R.A.1', $this->fees->id);
    statementsMap($this, 'B', 'B-C.A.2', $this->services->id);

    $checks = collect($this->getJson("/api/v1/associations/statements/{$this->year}/checks?form=ordinary")->assertOk()->json('data'))->keyBy('code');
    expect($checks['unmapped_management_accounts']['severity'])->toBe('error')
        ->and($checks['unmapped_management_accounts']['details']['accounts'][0]['account_code'])->toBe('7500')
        ->and($checks['cash_threshold_not_configured']['severity'])->toBe('info')
        ->and($checks['secondary_limits_not_configured']['severity'])->toBe('info');

    $this->postJson("/api/v1/associations/statements/{$this->year}/filings", ['form' => 'ordinary'])->assertUnprocessable();

    statementsMap($this, 'B', 'B-R.B.3', $this->bar->id);
    $this->putJson("/api/v1/associations/statements/{$this->year}/mission-report", ['answers' => ['1' => 'Sports and culture for the neighbourhood', '99' => 'ignored']])->assertSuccessful();
    $outline = $this->getJson("/api/v1/associations/statements/{$this->year}/mission-report")->assertOk()->json('data');
    expect($outline[0]['text'])->toBe('Sports and culture for the neighbourhood')->and($outline)->toHaveCount(24);

    $filing = $this->postJson("/api/v1/associations/statements/{$this->year}/filings", ['form' => 'ordinary'])->assertCreated()
        ->assertJsonPath('meta.official_register_format', false)->json('data');

    expect($filing['content_hash'])->toHaveLength(64)->and($filing['status'])->toBe('prepared');
    expect((string) $this->get("/api/v1/associations/statement-filings/{$filing['id']}/csv")->getContent())->toContain('B-R.A.1');
    expect($this->get("/api/v1/associations/statement-filings/{$filing['id']}/pdf")->assertOk()->headers->get('Content-Type'))->toBe('application/pdf');

    $this->postJson("/api/v1/associations/statement-filings/{$filing['id']}/filed", ['reason' => 'RUNTS protocol 123'])->assertOk()->assertJsonPath('data.status', 'filed');
    $this->postJson("/api/v1/associations/statement-filings/{$filing['id']}/filed", ['reason' => 'again'])->assertConflict();
});

test('the cash form is checked against the threshold parameter of the year, and an unmapped cash movement blocks it', function (): void {
    actAs($this, 'admin');
    statementsMapAll($this);
    LegalParameter::query()->create(['code' => LegalParameterService::CashStatementThreshold, 'year' => $this->year, 'value' => '1000', 'source_reference' => 'test value']);

    $checks = collect($this->getJson("/api/v1/associations/statements/{$this->year}/checks?form=cash")->json('data'))->keyBy('code');
    expect($checks['cash_form_not_allowed']['severity'])->toBe('error')->and($checks['cash_form_not_allowed']['details']['revenues'])->toBe('1200.00');

    LegalParameter::query()->where('code', LegalParameterService::CashStatementThreshold)->update(['value' => '5000']);
    StatementMapping::query()->where('scheme', 'D')->where('account_id', $this->bar->id)->delete();
    $checks = collect($this->getJson("/api/v1/associations/statements/{$this->year}/checks?form=cash")->json('data'))->keyBy('code');

    expect($checks['cash_threshold']['details']['cash_form_allowed'])->toBeTrue()
        ->and($checks)->toHaveKey('unmapped_cash_accounts')
        ->and($checks['cash_not_reconciled']['details']['difference'])->toBe('-200.00');
});

test('secondary activities and commercial prevalence are checked against the configured limits and flags', function (): void {
    actAs($this, 'admin');
    statementsMapAll($this);
    LegalParameter::query()->create(['code' => LegalParameterService::SecondaryRevenueSharePercent, 'year' => $this->year, 'value' => '10', 'source_reference' => 'test value']);

    $checks = collect($this->getJson("/api/v1/associations/statements/{$this->year}/checks")->json('data'))->keyBy('code');

    expect($checks['secondary_over_limits']['details']['share_of_revenues'])->toBe('16.66')
        ->and($checks['commercial_income_not_prevailing']['details']['commercial'])->toBe('200.00');
});

test('statements are read by the staff of the company only', function (): void {
    actAs($this, 'member');
    $this->getJson("/api/v1/associations/statements/{$this->year}/management-report")->assertForbidden();

    actAs($this, 'admin', tenant: $this->otherTenant);
    $report = $this->getJson("/api/v1/associations/statements/{$this->year}/management-report")->assertOk()->json('data');
    expect($report['totals']['revenues'])->toBe('0.00')->and($report['unmapped'])->toBe([]);
    $this->postJson('/api/v1/associations/statements/mappings', ['scheme' => 'B', 'line_code' => 'B-R.A.1', 'account_id' => $this->fees->id])->assertUnprocessable();
});
