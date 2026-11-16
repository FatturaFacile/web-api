<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\LeaseAccounting\Models\LeaseContract;
use Modules\LeaseAccounting\Models\LeasePeriodPosting;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 09:00:00');
    $this->tenant = Tenant::create(['name' => 'Officine Bianchi', 'legal_name' => 'Officine Bianchi Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->owner = leaseMemberOf($this->tenant, 'owner');
    leaseOpenYear($this->tenant, 2027);
});

afterEach(fn () => app(TenantContext::class)->clear());

function leaseMemberOf(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.it', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function leaseApi(User $user, Tenant $tenant): mixed
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

function leaseOpenYear(Tenant $tenant, int $year): void
{
    $fiscalYear = FiscalYear::factory()->create(['tenant_id' => $tenant->id, 'year_code' => "FY{$year}", 'start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"]);

    for ($month = 1; $month <= 12; $month++) {
        $start = sprintf('%d-%02d-01', $year, $month);
        FiscalPeriod::factory()->create([
            'tenant_id' => $tenant->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_number' => $month,
            'period_name' => "{$year}-{$month}",
            'start_date' => $start,
            'end_date' => date('Y-m-t', strtotime($start)),
            'status' => 'open',
        ]);
    }
}

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function leasePayload(array $override = []): array
{
    return array_merge([
        'title' => 'Sede di Milano',
        'lessor_name' => 'Immobiliare Rossi Srl',
        'asset_class' => 'real_estate',
        'currency' => 'EUR',
        'commencement_date' => '2027-01-01',
        'term_months' => 12,
        'payment_amount' => '1000.00',
        'incremental_borrowing_rate_percent' => '6.000000',
    ], $override);
}

/**
 * @param  array<string, mixed>  $override
 */
function leaseCreate(array $override = []): string
{
    return leaseApi(test()->owner, test()->tenant)->postJson('/api/v1/leases/contracts', leasePayload($override))->assertCreated()->json('data.id');
}

/**
 * Present value of n payments in arrears at a monthly-compounded annual rate, written independently of the module.
 */
function leasePvArrears(string $amount, int $count, string $annualPercent): string
{
    $growth = bcadd('1', bcdiv($annualPercent, '1200', 20), 20);
    $total = '0';

    for ($k = 1; $k <= $count; $k++) {
        $total = bcadd($total, bcdiv($amount, bcpow($growth, (string) $k, 20), 20), 20);
    }

    return bcround($total, 2);
}

function leaseAccountBalance(Tenant $tenant, string $code): string
{
    $accountId = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $code)->value('id');
    $row = DB::table('journal_lines')->where('tenant_id', $tenant->id)->where('account_id', $accountId)->selectRaw('SUM(functional_credit) AS credit, SUM(functional_debit) AS debit')->first();

    $balance = round((float) $row->credit - (float) $row->debit, 2);

    return number_format(abs($balance) < 0.005 ? 0.0 : $balance, 2, '.', '');
}

test('drafts get a progressive number and the terms are checked', function (): void {
    $api = leaseApi($this->owner, $this->tenant);

    $first = $api->postJson('/api/v1/leases/contracts', leasePayload())->assertCreated()->json('data');
    $second = $api->postJson('/api/v1/leases/contracts', leasePayload(['title' => 'Magazzino']))->assertCreated()->json('data');

    expect($first['number'])->toBe('LS-2027-0001')
        ->and($second['number'])->toBe('LS-2027-0002')
        ->and($first['status'])->toBe('draft')
        ->and($first['payment_frequency'])->toBe('monthly')
        ->and($first['payment_timing'])->toBe('arrears')
        ->and($first['payment_amount'])->toBe('1000.00');

    $api->postJson('/api/v1/leases/contracts', leasePayload(['payment_frequency' => 'quarterly', 'term_months' => 13]))->assertUnprocessable()->assertJsonValidationErrors('term_months');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['exemption' => 'short_term', 'term_months' => 24]))->assertUnprocessable()->assertJsonValidationErrors('exemption');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['exemption' => 'low_value', 'underlying_asset_value' => '6000.00']))->assertUnprocessable()->assertJsonValidationErrors('exemption');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['exemption' => 'low_value']))->assertUnprocessable()->assertJsonValidationErrors('underlying_asset_value');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['purchase_option_reasonably_certain' => true]))->assertUnprocessable()->assertJsonValidationErrors('purchase_option_price');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['extension_reasonably_certain' => true]))->assertUnprocessable()->assertJsonValidationErrors('extension_months');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['currency' => 'USD']))->assertUnprocessable()->assertJsonValidationErrors('currency');
    $api->postJson('/api/v1/leases/contracts', leasePayload(['lease_liability_account_id' => (string) Str::uuid7()]))->assertUnprocessable()->assertJsonValidationErrors('lease_liability_account_id');
});

test('the initial measurement is the present value of the payments at the effective rate', function (): void {
    $id = leaseCreate(['initial_direct_costs' => '500.00', 'incentives_received' => '200.00']);
    $api = leaseApi($this->owner, $this->tenant);

    $preview = $api->getJson("/api/v1/leases/contracts/{$id}/preview")->assertOk()->json('data');

    expect($preview['lease_liability'])->toBe('11618.93')
        ->and($preview['lease_liability'])->toBe(leasePvArrears('1000.00', 12, '6'))
        ->and($preview['rou_asset'])->toBe('11918.93')
        ->and($preview['total_undiscounted'])->toBe('12000.00')
        ->and($preview['lines'])->toHaveCount(12)
        ->and($preview['lines'][0]['interest'])->toBe('58.09')
        ->and($preview['lines'][0]['depreciation'])->toBe('993.24')
        ->and($preview['lines'][11]['closing_liability'])->toBe('0.00')
        ->and($preview['lines'][11]['rou_closing'])->toBe('0.00');

    $principal = '0';
    $interest = '0';
    $depreciation = '0';
    foreach ($preview['lines'] as $line) {
        $principal = bcadd($principal, $line['principal'], 2);
        $interest = bcadd($interest, $line['interest'], 2);
        $depreciation = bcadd($depreciation, $line['depreciation'], 2);
    }

    expect($principal)->toBe('11618.93')->and($interest)->toBe('381.07')->and($depreciation)->toBe('11918.93');
    expect(LeaseContract::query()->findOrFail($id)->status)->toBe('draft')->and(DB::table('lease_schedule_lines')->count())->toBe(0);
});

test('activation writes version 1 and posts the recognition journal', function (): void {
    $id = leaseCreate(['initial_direct_costs' => '500.00', 'incentives_received' => '200.00']);

    $lease = leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk()->json('data');

    expect($lease['status'])->toBe('active')
        ->and($lease['current_version_number'])->toBe(1)
        ->and($lease['versions'])->toHaveCount(1)
        ->and($lease['versions'][0]['reason'])->toBe('initial')
        ->and($lease['versions'][0]['liability_after'])->toBe('11618.93')
        ->and($lease['versions'][0]['rou_after'])->toBe('11918.93')
        ->and($lease['versions'][0]['journal_entry_id'])->not->toBeNull();

    $entry = JournalEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('source_document_type', 'lease_recognition')->firstOrFail();
    expect($entry->status)->toBe('posted')->and((string) $entry->posting_date->toDateString())->toBe('2027-01-01');

    expect(leaseAccountBalance($this->tenant, '2450'))->toBe('11618.93')
        ->and(leaseAccountBalance($this->tenant, '1670'))->toBe('-11918.93')
        ->and(leaseAccountBalance($this->tenant, '2460'))->toBe('300.00');

    leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/activate")->assertUnprocessable();
    expect(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'lease_recognition')->count())->toBe(1);
});

test('activation without an open fiscal period changes nothing', function (): void {
    $id = leaseCreate(['commencement_date' => '2030-01-01']);

    leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/activate")->assertUnprocessable()->assertJsonValidationErrors('posting');

    expect(LeaseContract::query()->findOrFail($id)->status)->toBe('draft')
        ->and(DB::table('lease_schedule_versions')->count())->toBe(0)
        ->and(DB::table('lease_schedule_lines')->count())->toBe(0);

    $active = leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/activate", ['post_recognition' => false])->assertOk()->json('data');
    expect($active['status'])->toBe('active')->and($active['versions'][0]['journal_entry_id'])->toBeNull();
});

test('payments in advance, quarterly payments, fixed escalation and a purchase option shape the schedule', function (): void {
    $api = leaseApi($this->owner, $this->tenant);

    $advance = leaseCreate(['payment_timing' => 'advance']);
    expect($api->getJson("/api/v1/leases/contracts/{$advance}/preview")->json('data.lease_liability'))->toBe('11677.03');

    $quarterly = leaseCreate(['payment_frequency' => 'quarterly', 'payment_amount' => '3000.00']);
    $preview = $api->getJson("/api/v1/leases/contracts/{$quarterly}/preview")->json('data');
    $paying = collect($preview['lines'])->filter(fn (array $line): bool => $line['payment'] !== '0.00')->pluck('period_number')->all();
    expect($preview['lease_liability'])->toBe('11561.03')->and($paying)->toBe([3, 6, 9, 12]);

    $fixed = leaseCreate(['term_months' => 24, 'escalation_type' => 'fixed', 'escalation_rate_percent' => '3.000000']);
    $preview = $api->getJson("/api/v1/leases/contracts/{$fixed}/preview")->json('data');
    expect($preview['lease_liability'])->toBe('22891.18')
        ->and($preview['lines'][11]['payment'])->toBe('1000.00')
        ->and($preview['lines'][12]['payment'])->toBe('1030.00')
        ->and($preview['lines'][23]['closing_liability'])->toBe('0.00');

    $index = leaseCreate(['term_months' => 24, 'escalation_type' => 'index', 'escalation_rate_percent' => '3.000000']);
    $preview = $api->getJson("/api/v1/leases/contracts/{$index}/preview")->json('data');
    expect($preview['lease_liability'])->toBe(leasePvArrears('1000.00', 24, '6'))->and($preview['lines'][12]['payment'])->toBe('1000.00');

    $purchase = leaseCreate(['purchase_option_reasonably_certain' => true, 'purchase_option_price' => '5000.00', 'useful_life_months' => 60]);
    $preview = $api->getJson("/api/v1/leases/contracts/{$purchase}/preview")->json('data');
    expect($preview['total_undiscounted'])->toBe('17000.00')
        ->and($preview['lines'][11]['payment'])->toBe('6000.00')
        ->and($preview['lines'][11]['closing_liability'])->toBe('0.00')
        ->and(bccomp($preview['lines'][11]['rou_closing'], '0.00', 2))->toBe(1);
});

test('a lease with an exemption is expensed and has no schedule', function (): void {
    $api = leaseApi($this->owner, $this->tenant);
    $id = leaseCreate(['exemption' => 'short_term', 'term_months' => 6, 'payment_amount' => '500.00']);

    $api->getJson("/api/v1/leases/contracts/{$id}/preview")->assertUnprocessable();
    $lease = $api->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk()->json('data');

    expect($lease['status'])->toBe('active')->and($lease['current_version_number'])->toBe(0)->and($lease['versions'])->toBe([]);
    expect($api->getJson("/api/v1/leases/contracts/{$id}/schedule")->assertOk()->json('data'))->toBe([]);
    $api->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2027-05-01'])->assertUnprocessable();
    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", ['effective_date' => '2027-02-01', 'reason' => 'index_change', 'new_payment_amount' => '600.00'])->assertUnprocessable();
    expect(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'lease_recognition')->count())->toBe(0);
});

test('periods post once, in order, and the ledger liability follows the schedule to zero', function (): void {
    $id = leaseCreate();
    $api = leaseApi($this->owner, $this->tenant);
    $api->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk();

    $api->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2027-05-13'])->assertUnprocessable()->assertJsonValidationErrors('through_date');

    $posted = $api->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2027-03-31'])->assertOk()->json('data');
    expect($posted)->toHaveCount(3)->and(array_column($posted, 'period_number'))->toBe([1, 2, 3])->and($posted[0]['posting_date'])->toBe('2027-01-31');

    $schedule = $api->getJson("/api/v1/leases/contracts/{$id}/schedule")->json('data');
    expect(leaseAccountBalance($this->tenant, '2450'))->toBe($schedule[2]['closing_liability'])
        ->and(collect($schedule)->pluck('status')->take(4)->all())->toBe(['posted', 'posted', 'posted', 'scheduled']);

    expect($api->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2027-03-31'])->assertOk()->json('data'))->toBe([]);
    expect(LeasePeriodPosting::query()->count())->toBe(3)->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'lease_period')->count())->toBe(3);

    $this->travelTo('2028-01-15 09:00:00');
    $rest = leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2028-01-15'])->assertOk()->json('data');

    expect($rest)->toHaveCount(9)
        ->and(LeaseContract::query()->findOrFail($id)->status)->toBe('expired')
        ->and(leaseAccountBalance($this->tenant, '2450'))->toBe('0.00')
        ->and(bcadd(leaseAccountBalance($this->tenant, '1670'), leaseAccountBalance($this->tenant, '1675'), 2))->toBe('0.00')
        ->and(JournalEntry::withoutGlobalScopes()->where('source_document_type', 'lease_period')->count())->toBe(12);

    leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2028-01-15'])->assertUnprocessable();
});

test('the run posts every active lease and reports the one the ledger refuses', function (): void {
    $good = leaseCreate();
    $closedYear = leaseCreate(['commencement_date' => '2026-11-01', 'title' => 'Deposito']);
    $api = leaseApi($this->owner, $this->tenant);
    $api->postJson("/api/v1/leases/contracts/{$good}/activate")->assertOk();
    $api->postJson("/api/v1/leases/contracts/{$closedYear}/activate", ['post_recognition' => false])->assertOk();

    $results = collect($api->postJson('/api/v1/leases/postings/run', ['through_date' => '2027-02-28'])->assertOk()->json('data'))->keyBy('contract_id');

    expect($results[$good]['posted_periods'])->toBe(2)->and($results[$good]['error'])->toBeNull()
        ->and($results[$closedYear]['posted_periods'])->toBe(0)->and($results[$closedYear]['error'])->not->toBeNull();
});

test('an index change keeps the rate, adjusts the asset and creates a new version', function (): void {
    $id = leaseCreate(['term_months' => 24]);
    $api = leaseApi($this->owner, $this->tenant);
    $api->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk();
    $api->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2027-05-01'])->assertOk()->assertJsonCount(4, 'data');

    $before = collect($api->getJson("/api/v1/leases/contracts/{$id}/schedule")->json('data'))->firstWhere('period_number', 8);
    $remeasure = ['effective_date' => '2027-08-01', 'reason' => 'index_change', 'new_payment_amount' => '1050.00'];

    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", [...$remeasure, 'effective_date' => '2027-04-01'])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", [...$remeasure, 'effective_date' => '2027-08-15'])->assertUnprocessable()->assertJsonValidationErrors('effective_date');
    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", [...$remeasure, 'new_discount_rate_percent' => '7.000000'])->assertUnprocessable()->assertJsonValidationErrors('new_discount_rate_percent');
    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", [...$remeasure, 'remaining_term_months' => 12])->assertUnprocessable()->assertJsonValidationErrors('remaining_term_months');
    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", ['effective_date' => '2027-08-01', 'reason' => 'index_change'])->assertUnprocessable()->assertJsonValidationErrors('new_payment_amount');
    $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", ['effective_date' => '2027-08-01', 'reason' => 'modification', 'new_payment_amount' => '900.00'])->assertUnprocessable()->assertJsonValidationErrors('new_discount_rate_percent');

    $version = $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", $remeasure)->assertCreated()->json('data');
    $expectedLiability = leasePvArrears('1050.00', 17, '6');

    expect($version['version_number'])->toBe(2)
        ->and($version['reason'])->toBe('index_change')
        ->and($version['start_period_number'])->toBe(8)
        ->and($version['end_period_number'])->toBe(24)
        ->and($version['discount_rate_percent'])->toBe('6.000000')
        ->and($version['liability_before'])->toBe($before['opening_liability'])
        ->and($version['liability_after'])->toBe($expectedLiability)
        ->and($version['liability_adjustment'])->toBe(bcsub($expectedLiability, $before['opening_liability'], 2))
        ->and($version['rou_before'])->toBe($before['rou_opening'])
        ->and($version['rou_after'])->toBe(bcadd($before['rou_opening'], $version['liability_adjustment'], 2))
        ->and($version['result_amount'])->toBe('0.00')
        ->and($version['journal_entry_id'])->not->toBeNull();

    $schedule = collect($api->getJson("/api/v1/leases/contracts/{$id}/schedule")->json('data'));
    $versions = $api->getJson("/api/v1/leases/contracts/{$id}/versions")->json('data');

    expect($schedule)->toHaveCount(24)
        ->and($schedule->where('schedule_version_id', $version['id'])->pluck('period_number')->min())->toBe(8)
        ->and($schedule->firstWhere('period_number', 8)['payment'])->toBe('1050.00')
        ->and($schedule->firstWhere('period_number', 7)['payment'])->toBe('1000.00')
        ->and($schedule->last()['closing_liability'])->toBe('0.00')
        ->and($schedule->last()['rou_closing'])->toBe('0.00')
        ->and(array_column($versions, 'status'))->toBe(['superseded', 'current']);

    $history = $api->getJson("/api/v1/leases/contracts/{$id}/schedule?version_number=1")->json('data');
    expect($history)->toHaveCount(24)->and(collect($history)->where('status', 'superseded')->count())->toBe(17);

    $this->travelTo('2027-10-15 09:00:00');
    leaseApi($this->owner, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/post-periods", ['through_date' => '2027-09-30'])->assertOk()->assertJsonCount(5, 'data');
    $nine = collect(leaseApi($this->owner, $this->tenant)->getJson("/api/v1/leases/contracts/{$id}/schedule")->json('data'))->firstWhere('period_number', 9);

    expect(leaseAccountBalance($this->tenant, '2450'))->toBe($nine['closing_liability']);
});

test('a modification with a revised rate beyond the asset is a gain', function (): void {
    $id = leaseCreate(['term_months' => 24, 'incentives_received' => '5000.00']);
    $api = leaseApi($this->owner, $this->tenant);
    $api->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk();

    $version = $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", ['effective_date' => '2027-08-01', 'reason' => 'modification', 'new_payment_amount' => '1.00', 'new_discount_rate_percent' => '6.000000'])->assertCreated()->json('data');

    expect(bccomp($version['liability_adjustment'], '0.00', 2))->toBe(-1)
        ->and($version['rou_after'])->toBe('0.00')
        ->and($version['rou_adjustment'])->toBe(bcsub('0.00', $version['rou_before'], 2))
        ->and(bccomp($version['result_amount'], '0.00', 2))->toBe(1)
        ->and(bcadd($version['rou_before'], $version['result_amount'], 2))->toBe(bcsub('0.00', $version['liability_adjustment'], 2));

    $schedule = collect($api->getJson("/api/v1/leases/contracts/{$id}/schedule")->json('data'));
    expect($schedule->where('period_number', '>=', 8)->sum(fn (array $line): float => (float) $line['depreciation']))->toEqual(0.0);

    $account = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('account_code', '6860')->firstOrFail();
    expect(number_format((float) DB::table('journal_lines')->where('account_id', $account->id)->sum('functional_credit'), 2, '.', ''))->toBe($version['result_amount']);
});

test('a reassessment that shortens the term uses the revised rate', function (): void {
    $id = leaseCreate(['term_months' => 24]);
    $api = leaseApi($this->owner, $this->tenant);
    $api->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk();

    $version = $api->postJson("/api/v1/leases/contracts/{$id}/remeasure", ['effective_date' => '2027-07-01', 'reason' => 'reassessment', 'remaining_term_months' => 12, 'new_discount_rate_percent' => '5.000000', 'post_journal' => false])->assertCreated()->json('data');

    expect($version['end_period_number'])->toBe(18)
        ->and($version['payment_amount'])->toBe('1000.00')
        ->and($version['liability_after'])->toBe(leasePvArrears('1000.00', 12, '5'))
        ->and($version['journal_entry_id'])->toBeNull();

    $schedule = collect($api->getJson("/api/v1/leases/contracts/{$id}/schedule")->json('data'));
    expect($schedule)->toHaveCount(18)->and($schedule->last()['closing_liability'])->toBe('0.00');
});

test('the maturity analysis buckets the undiscounted payments and sets them against the liability', function (): void {
    $api = leaseApi($this->owner, $this->tenant);
    $long = leaseCreate(['term_months' => 120]);
    $swiss = leaseCreate(['currency' => 'CHF', 'accounting_standard' => 'swiss_gaap_fer', 'payment_amount' => '2000.00']);
    $exempt = leaseCreate(['exemption' => 'short_term', 'term_months' => 6, 'payment_amount' => '500.00']);
    leaseCreate(['title' => 'Draft only']);

    $api->postJson("/api/v1/leases/contracts/{$long}/activate")->assertOk();
    $api->postJson("/api/v1/leases/contracts/{$swiss}/activate", ['post_recognition' => false])->assertOk();
    $api->postJson("/api/v1/leases/contracts/{$exempt}/activate")->assertOk();

    $report = $api->getJson('/api/v1/leases/reports/maturity?as_of=2027-01-31')->assertOk()->json('data');
    $sections = collect($report['currencies'])->keyBy('currency');
    $eur = $sections['EUR'];
    $row = $eur['leases'][0];
    $schedule = collect($api->getJson("/api/v1/leases/contracts/{$long}/schedule")->json('data'));

    expect($report['as_of'])->toBe('2027-01-31')
        ->and(array_keys($sections->all()))->toBe(['CHF', 'EUR'])
        ->and($row['within_one_year'])->toBe('12000.00')
        ->and($row['one_to_five_years'])->toBe('48000.00')
        ->and($row['over_five_years'])->toBe('59000.00')
        ->and($row['total_undiscounted'])->toBe('119000.00')
        ->and($row['total_discounted'])->toBe($schedule->firstWhere('period_number', 1)['closing_liability'])
        ->and($row['unearned_interest'])->toBe(bcsub('119000.00', $row['total_discounted'], 2))
        ->and($row['current_liability'])->toBe(bcsub($row['total_discounted'], $schedule->firstWhere('period_number', 13)['closing_liability'], 2))
        ->and(bcadd($row['current_liability'], $row['non_current_liability'], 2))->toBe($row['total_discounted'])
        ->and($row['rou_carrying_amount'])->toBe($schedule->firstWhere('period_number', 1)['rou_closing'])
        ->and($eur['totals']['total_undiscounted'])->toBe('119000.00')
        ->and($eur['exempt_commitments']['within_one_year'])->toBe('2500.00')
        ->and($sections['CHF']['leases'][0]['total_undiscounted'])->toBe('22000.00')
        ->and($sections['CHF']['totals']['within_one_year'])->toBe('22000.00');

    $onlyChf = $api->getJson('/api/v1/leases/reports/maturity?as_of=2027-01-31&currency=CHF')->assertOk()->json('data.currencies');
    expect($onlyChf)->toHaveCount(1)->and($onlyChf[0]['currency'])->toBe('CHF');
    $api->getJson('/api/v1/leases/reports/maturity?currency=USD')->assertUnprocessable();
});

test('drafts can be changed, deleted or cancelled; active leases cannot', function (): void {
    $api = leaseApi($this->owner, $this->tenant);
    $draft = leaseCreate();
    $other = leaseCreate(['title' => 'Altro']);

    $updated = $api->putJson("/api/v1/leases/contracts/{$draft}", ['payment_amount' => '1200.00', 'term_months' => 24])->assertOk()->json('data');
    expect($updated['payment_amount'])->toBe('1200.00')->and($updated['term_months'])->toBe(24)->and($updated['title'])->toBe('Sede di Milano');
    $api->putJson("/api/v1/leases/contracts/{$draft}", ['payment_frequency' => 'quarterly', 'term_months' => 25])->assertUnprocessable();

    $api->postJson("/api/v1/leases/contracts/{$draft}/activate")->assertOk();
    $api->putJson("/api/v1/leases/contracts/{$draft}", ['payment_amount' => '1300.00'])->assertUnprocessable();
    $api->deleteJson("/api/v1/leases/contracts/{$draft}")->assertUnprocessable();
    $api->postJson("/api/v1/leases/contracts/{$draft}/cancel", ['reason' => 'No longer needed'])->assertUnprocessable();

    $cancelled = $api->postJson("/api/v1/leases/contracts/{$other}/cancel", ['reason' => 'Signed elsewhere'])->assertOk()->json('data');
    expect($cancelled['status'])->toBe('cancelled')->and($cancelled['cancellation_reason'])->toBe('Signed elsewhere');
    $api->postJson("/api/v1/leases/contracts/{$other}/cancel", ['reason' => 'Again'])->assertUnprocessable();
    $api->postJson("/api/v1/leases/contracts/{$other}/activate")->assertUnprocessable();

    $third = leaseCreate(['title' => 'Da eliminare']);
    $api->deleteJson("/api/v1/leases/contracts/{$third}")->assertNoContent();
    $api->getJson("/api/v1/leases/contracts/{$third}")->assertNotFound();

    $list = $api->getJson('/api/v1/leases/contracts?status=active')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['id'])->toBe($draft);
    expect($api->getJson('/api/v1/leases/contracts?search=Altro')->json('data'))->toHaveCount(1);
});

test('leases stay inside the company and the roles decide who reads and who posts', function (): void {
    $id = leaseCreate();
    $other = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = leaseMemberOf($other, 'owner');

    leaseApi($stranger, $other)->getJson("/api/v1/leases/contracts/{$id}")->assertNotFound();
    leaseApi($stranger, $other)->postJson("/api/v1/leases/contracts/{$id}/activate")->assertNotFound();
    expect(leaseApi($stranger, $other)->getJson('/api/v1/leases/contracts')->assertOk()->json('data'))->toBe([]);
    expect(leaseApi($stranger, $other)->getJson('/api/v1/leases/reports/maturity')->assertOk()->json('data.currencies'))->toBe([]);

    $reader = leaseMemberOf($this->tenant, 'accountant_readonly');
    leaseApi($reader, $this->tenant)->getJson('/api/v1/leases/contracts')->assertOk();
    leaseApi($reader, $this->tenant)->postJson('/api/v1/leases/contracts', leasePayload())->assertForbidden();

    $member = leaseMemberOf($this->tenant, 'member');
    leaseApi($member, $this->tenant)->getJson('/api/v1/leases/contracts')->assertForbidden();

    $accountant = leaseMemberOf($this->tenant, 'accountant');
    leaseApi($accountant, $this->tenant)->postJson("/api/v1/leases/contracts/{$id}/activate")->assertOk();
});
