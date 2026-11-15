<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Budgeting\Models\BudgetDriver;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = bdgTenant('Controllo');
    $this->owner = bdgMember($this->tenant, 'owner');
    bdgAct($this->owner, $this->tenant);
    $this->expense = bdgAccount($this->tenant, 'expense', 'debit');
    $this->revenue = bdgAccount($this->tenant, 'revenue', 'credit');
});

afterEach(fn () => app(TenantContext::class)->clear());

function bdgTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function bdgMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@budgeting-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function bdgAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function bdgAccount(Tenant $tenant, string $kind, string $normalBalance): ChartOfAccount
{
    return ChartOfAccount::factory()->create(['tenant_id' => $tenant->id, 'account_class' => $kind, 'account_type' => $kind, 'type' => $kind, 'normal_balance' => $normalBalance, 'name' => ucfirst($kind).' '.Str::random(4)]);
}

function bdgCostCenter(Tenant $tenant): CostCenter
{
    return CostCenter::create(['tenant_id' => $tenant->id, 'code' => 'CC-'.Str::upper(Str::random(5)), 'name' => 'Cost center '.Str::random(4), 'is_active' => true]);
}

/**
 * Posts a journal line on an account in the ledger of the company (a posted entry on the given date).
 */
function bdgPost(Tenant $tenant, ChartOfAccount $account, string $date, string $debit, string $credit, ?CostCenter $costCenter = null, string $status = 'posted'): void
{
    $period = FiscalPeriod::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->first() ?? FiscalPeriod::factory()->create(['tenant_id' => $tenant->id]);
    $entry = JournalEntry::factory()->create(['tenant_id' => $tenant->id, 'fiscal_period_id' => $period->id, 'posting_date' => $date, 'document_date' => $date, 'status' => $status]);
    JournalLine::create([
        'tenant_id' => $tenant->id,
        'journal_entry_id' => $entry->id,
        'posting_date' => $date,
        'line_number' => 1,
        'account_id' => $account->id,
        'cost_center_id' => $costCenter?->id,
        'description' => 'Actual',
        'debit_amount' => $debit,
        'credit_amount' => $credit,
        'currency' => 'EUR',
        'exchange_rate' => 1,
        'amount_in_functional_currency' => bcsub($debit, $credit, 2),
    ]);
}

function bdgMoney(mixed $value): string
{
    return number_format((float) $value, 2, '.', '');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bdgVersion(array $overrides = []): array
{
    return test()->postJson('/api/v1/budgeting/versions', [
        'name' => 'Budget '.Str::random(5),
        'fiscal_year' => 2028,
        'version_type' => 'budget',
        'currency' => 'EUR',
        ...$overrides,
    ])->assertCreated()->json('data');
}

function bdgLine(string $versionId, string $accountId, int $period, string $amount, ?string $costCenterId = null): TestResponse
{
    return test()->postJson("/api/v1/budgeting/versions/{$versionId}/lines", ['account_id' => $accountId, 'cost_center_id' => $costCenterId, 'period' => $period, 'amount' => $amount]);
}

/**
 * Walks a version to the approved status as the owner.
 */
function bdgApprove(string $versionId): void
{
    test()->postJson("/api/v1/budgeting/versions/{$versionId}/submit")->assertOk();
    test()->postJson("/api/v1/budgeting/versions/{$versionId}/approve")->assertOk();
}

test('a version is created for a fiscal year with a unique name and valid seasonal weights', function (): void {
    $version = bdgVersion(['name' => 'Budget 2028']);
    expect($version['status'])->toBe('draft')->and($version['year_start'])->toBe('2028-01-01')->and($version['lines_count'] ?? 0)->toBe(0);

    $this->postJson('/api/v1/budgeting/versions', ['name' => 'Budget 2028', 'fiscal_year' => 2028, 'version_type' => 'forecast', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('name');
    bdgVersion(['name' => 'Budget 2028', 'fiscal_year' => 2029]);

    $this->postJson('/api/v1/budgeting/versions', ['name' => 'Bad weights', 'fiscal_year' => 2028, 'version_type' => 'budget', 'currency' => 'EUR', 'seasonal_weights' => [1, 2, 3]])->assertUnprocessable()->assertJsonValidationErrors('seasonal_weights');
    $this->postJson('/api/v1/budgeting/versions', ['name' => 'Zero weights', 'fiscal_year' => 2028, 'version_type' => 'budget', 'currency' => 'EUR', 'seasonal_weights' => array_fill(0, 12, 0)])->assertUnprocessable()->assertJsonValidationErrors('seasonal_weights');
    $this->postJson('/api/v1/budgeting/versions', ['name' => 'Bad', 'fiscal_year' => 1900, 'version_type' => 'nope', 'currency' => 'EURO'])->assertUnprocessable()->assertJsonValidationErrors(['fiscal_year', 'version_type', 'currency']);

    $this->putJson("/api/v1/budgeting/versions/{$version['id']}", ['description' => 'Updated', 'year_start' => '2028-04-01'])->assertOk()->assertJsonPath('data.year_start', '2028-04-01');
    $this->getJson('/api/v1/budgeting/versions?fiscal_year=2029')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/budgeting/versions/{$version['id']}")->assertNoContent();
    expect(BudgetVersion::query()->count())->toBe(1);
});

test('saving a line twice replaces the amount and the dimensions must exist in the company', function (): void {
    $version = bdgVersion();
    $center = bdgCostCenter($this->tenant);

    $first = bdgLine($version['id'], $this->expense->id, 1, '100', $center->id)->assertCreated()->json('data');
    $second = bdgLine($version['id'], $this->expense->id, 1, '250.5', $center->id)->assertCreated()->json('data');
    expect($second['id'])->toBe($first['id'])->and($second['amount'])->toBe('250.50');
    bdgLine($version['id'], $this->expense->id, 1, '10')->assertCreated();
    expect(BudgetLine::query()->where('version_id', $version['id'])->count())->toBe(2);

    bdgLine($version['id'], $this->expense->id, 13, '1')->assertUnprocessable()->assertJsonValidationErrors('period');
    bdgLine($version['id'], $this->expense->id, 1, '-1')->assertUnprocessable()->assertJsonValidationErrors('amount');
    bdgLine($version['id'], (string) Str::uuid(), 1, '1')->assertUnprocessable()->assertJsonValidationErrors('account_id');
    bdgLine($version['id'], $this->expense->id, 1, '1', (string) Str::uuid())->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/lines", ['account_id' => $this->expense->id, 'period' => 2, 'amount' => '1', 'project_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('project_id');

    $foreign = bdgTenant('Altra');
    $foreignAccount = bdgAccount($foreign, 'expense', 'debit');
    bdgLine($version['id'], $foreignAccount->id, 1, '1')->assertUnprocessable()->assertJsonValidationErrors('account_id');

    $this->putJson("/api/v1/budgeting/lines/{$first['id']}", ['amount' => '99'])->assertOk()->assertJsonPath('data.amount', '99.00');
    $this->putJson("/api/v1/budgeting/lines/{$first['id']}", ['cost_center_id' => null])->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->deleteJson("/api/v1/budgeting/lines/{$first['id']}")->assertNoContent();
    $this->getJson("/api/v1/budgeting/versions/{$version['id']}/lines?period=1")->assertOk()->assertJsonCount(1, 'data');
});

test('spreading an amount allocates it evenly, by season or by percentage and always adds up to the cent', function (): void {
    $weights = [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 3];
    $version = bdgVersion(['seasonal_weights' => $weights]);
    $id = $version['id'];

    $even = $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1000.00', 'method' => 'even', 'start_period' => 1, 'end_period' => 3])->assertCreated()->json('data');
    expect(array_column($even, 'amount'))->toBe(['333.33', '333.33', '333.34'])->and(array_unique(array_column($even, 'source')))->toBe(['spread']);

    $seasonal = $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->revenue->id, 'total_amount' => '1400', 'method' => 'seasonal', 'start_period' => 1, 'end_period' => 12])->assertCreated()->json('data');
    expect($seasonal[0]['amount'])->toBe('100.00')->and($seasonal[11]['amount'])->toBe('300.00')
        ->and(bdgMoney(array_sum(array_map(fn ($line) => (float) $line['amount'], $seasonal))))->toBe('1400.00');

    $percent = $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1000', 'method' => 'percentage', 'start_period' => 4, 'end_period' => 6, 'percentages' => [50, 30, 20]])->assertCreated()->json('data');
    expect(array_column($percent, 'amount'))->toBe(['500.00', '300.00', '200.00']);

    $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1', 'method' => 'percentage', 'start_period' => 1, 'end_period' => 3, 'percentages' => [50, 30]])->assertUnprocessable()->assertJsonValidationErrors('percentages');
    $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1', 'method' => 'percentage', 'start_period' => 1, 'end_period' => 2, 'percentages' => [50, 30]])->assertUnprocessable()->assertJsonValidationErrors('percentages');
    $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1', 'method' => 'even', 'start_period' => 5, 'end_period' => 2])->assertUnprocessable()->assertJsonValidationErrors('end_period');
    $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1', 'method' => 'random', 'start_period' => 1, 'end_period' => 2])->assertUnprocessable()->assertJsonValidationErrors('method');

    $plain = bdgVersion();
    $this->postJson("/api/v1/budgeting/versions/{$plain['id']}/spread", ['account_id' => $this->expense->id, 'total_amount' => '100', 'method' => 'seasonal', 'start_period' => 1, 'end_period' => 4])->assertUnprocessable()->assertJsonValidationErrors('spread_method');

    $replaced = $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '600', 'method' => 'even', 'start_period' => 1, 'end_period' => 3])->assertCreated()->json('data');
    expect(array_column($replaced, 'amount'))->toBe(['200.00', '200.00', '200.00']);
    expect(BudgetLine::query()->where('version_id', $id)->where('account_id', $this->expense->id)->count())->toBe(6);
});

test('a driver generates its lines from quantity times rate and regenerating replaces them', function (): void {
    $version = bdgVersion(['seasonal_weights' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1]]);
    $id = $version['id'];

    $driver = $this->postJson("/api/v1/budgeting/versions/{$id}/drivers", [
        'name' => 'Headcount', 'account_id' => $this->expense->id, 'quantity' => '10', 'rate' => '2500', 'spread_method' => 'even', 'start_period' => 1, 'end_period' => 12,
    ])->assertCreated()->assertJsonPath('data.total_amount', '25000.00')->json('data');
    expect(BudgetLine::query()->where('version_id', $id)->count())->toBe(0);

    $lines = $this->postJson("/api/v1/budgeting/drivers/{$driver['id']}/generate")->assertOk()->json('data');
    expect($lines)->toHaveCount(12)->and($lines[0]['amount'])->toBe('2083.33')->and($lines[11]['amount'])->toBe('2083.37')->and($lines[0]['driver_id'])->toBe($driver['id']);

    $this->putJson("/api/v1/budgeting/drivers/{$driver['id']}", ['quantity' => '12', 'start_period' => 7])->assertOk()->assertJsonPath('data.total_amount', '30000.00');
    $this->postJson("/api/v1/budgeting/drivers/{$driver['id']}/generate")->assertOk()->assertJsonCount(6, 'data');
    expect(BudgetLine::query()->where('driver_id', $driver['id'])->count())->toBe(6)->and(bdgMoney(BudgetLine::query()->where('driver_id', $driver['id'])->sum('amount')))->toBe('30000.00');

    $percent = $this->postJson("/api/v1/budgeting/versions/{$id}/drivers", ['name' => 'Licences', 'account_id' => $this->revenue->id, 'quantity' => '4', 'rate' => '250', 'spread_method' => 'percentage', 'percentages' => [25, 75], 'start_period' => 1, 'end_period' => 2])->assertCreated()->json('data');
    $this->postJson("/api/v1/budgeting/versions/{$id}/generate-drivers")->assertOk()->assertJsonPath('data', ['drivers' => 2, 'lines' => 8]);
    expect(BudgetLine::query()->where('driver_id', $percent['id'])->orderBy('period')->pluck('amount')->all())->toBe(['250.00', '750.00']);

    $this->postJson("/api/v1/budgeting/versions/{$id}/drivers", ['name' => 'Bad', 'account_id' => $this->expense->id, 'quantity' => '1', 'rate' => '1', 'spread_method' => 'percentage', 'percentages' => [60, 60], 'start_period' => 1, 'end_period' => 2])->assertUnprocessable()->assertJsonValidationErrors('percentages');
    $this->postJson("/api/v1/budgeting/versions/{$id}/drivers", ['name' => 'Bad', 'account_id' => (string) Str::uuid(), 'quantity' => '1', 'rate' => '1', 'spread_method' => 'even', 'start_period' => 1, 'end_period' => 2])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->postJson("/api/v1/budgeting/versions/{$id}/drivers", ['name' => 'Bad', 'account_id' => $this->expense->id, 'quantity' => '1', 'rate' => '1', 'spread_method' => 'even', 'start_period' => 9, 'end_period' => 2])->assertUnprocessable()->assertJsonValidationErrors('end_period');

    $this->putJson("/api/v1/budgeting/drivers/{$driver['id']}", ['is_active' => false])->assertOk();
    $this->postJson("/api/v1/budgeting/drivers/{$driver['id']}/generate")->assertOk()->assertJsonCount(0, 'data');
    expect(BudgetLine::query()->where('driver_id', $driver['id'])->count())->toBe(0);

    $this->deleteJson("/api/v1/budgeting/drivers/{$percent['id']}")->assertNoContent();
    expect(BudgetLine::query()->where('driver_id', $percent['id'])->count())->toBe(0)->and(BudgetDriver::query()->count())->toBe(1);
});

test('the approval workflow locks the version and allows one approved budget per fiscal year', function (): void {
    $version = bdgVersion(['name' => 'Budget A']);
    $id = $version['id'];

    $this->postJson("/api/v1/budgeting/versions/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors('status');
    bdgLine($id, $this->expense->id, 1, '100')->assertCreated();
    $this->postJson("/api/v1/budgeting/versions/{$id}/approve")->assertUnprocessable();

    $this->postJson("/api/v1/budgeting/versions/{$id}/submit", ['comment' => 'Please check'])->assertOk()->assertJsonPath('data.status', 'submitted');
    bdgLine($id, $this->expense->id, 2, '100')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->putJson("/api/v1/budgeting/versions/{$id}", ['name' => 'Renamed'])->assertUnprocessable();
    $this->deleteJson("/api/v1/budgeting/versions/{$id}")->assertUnprocessable();
    $this->postJson("/api/v1/budgeting/versions/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson("/api/v1/budgeting/versions/{$id}/reject", ['comment' => 'Too high'])->assertOk()->assertJsonPath('data.status', 'draft');
    bdgLine($id, $this->expense->id, 2, '100')->assertCreated();

    bdgApprove($id);
    $other = bdgVersion(['name' => 'Budget B']);
    bdgLine($other['id'], $this->expense->id, 1, '5')->assertCreated();
    $this->postJson("/api/v1/budgeting/versions/{$other['id']}/submit")->assertOk();
    $this->postJson("/api/v1/budgeting/versions/{$other['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');

    $forecast = bdgVersion(['name' => 'Forecast', 'version_type' => 'forecast']);
    bdgLine($forecast['id'], $this->expense->id, 1, '5')->assertCreated();
    bdgApprove($forecast['id']);

    $this->postJson("/api/v1/budgeting/versions/{$id}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
    $locked = BudgetVersion::query()->findOrFail($id);
    $locked->name = 'Sneaky';
    expect(fn () => $locked->save())->toThrow(LogicException::class);
    expect(fn () => BudgetLine::query()->where('version_id', $id)->first()->update(['amount' => '1']))->toThrow(LogicException::class);
    $this->postJson("/api/v1/budgeting/versions/{$id}/reject", ['comment' => 'x'])->assertUnprocessable();

    $actions = collect($this->getJson("/api/v1/budgeting/versions/{$id}")->json('data.approvals'))->pluck('action')->all();
    expect($actions)->toBe(['submit', 'reject', 'submit', 'approve', 'lock']);
});

test('only approvers decide and read-only roles never write', function (): void {
    $version = bdgVersion();
    bdgLine($version['id'], $this->expense->id, 1, '10');

    $planner = bdgMember($this->tenant, 'member');
    bdgAct($planner, $this->tenant);
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/submit")->assertOk();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/approve")->assertForbidden();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/reject", ['comment' => 'no'])->assertForbidden();

    $granted = User::create(['name' => 'Granted '.Str::random(4), 'email' => Str::random(10).'@budgeting-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $granted->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['budgeting.approve'])]);
    bdgAct($granted, $this->tenant);
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/approve")->assertOk();

    bdgAct(bdgMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/budgeting/versions')->assertOk();
    $this->postJson('/api/v1/budgeting/versions', ['name' => 'Nope', 'fiscal_year' => 2028, 'version_type' => 'budget', 'currency' => 'EUR'])->assertForbidden();
});

test('the variance compares the budget with the actuals of the ledger', function (): void {
    $version = bdgVersion();
    $id = $version['id'];
    $center = bdgCostCenter($this->tenant);

    foreach ([1, 2, 3] as $period) {
        bdgLine($id, $this->expense->id, $period, '1000', $center->id)->assertCreated();
        bdgLine($id, $this->revenue->id, $period, '5000')->assertCreated();
    }

    bdgPost($this->tenant, $this->expense, '2028-01-15', '800.00', '0', $center);
    bdgPost($this->tenant, $this->expense, '2028-02-10', '1300.00', '100.00', $center);
    bdgPost($this->tenant, $this->expense, '2028-02-20', '400.00', '0');
    bdgPost($this->tenant, $this->expense, '2028-05-02', '9999.00', '0', $center);
    bdgPost($this->tenant, $this->expense, '2028-01-20', '7777.00', '0', $center, 'draft');
    bdgPost($this->tenant, $this->revenue, '2028-01-31', '0', '6000.00');
    bdgPost($this->tenant, $this->revenue, '2028-02-28', '200.00', '3000.00');

    $other = bdgTenant('Altra');
    bdgPost($other, bdgAccount($other, 'expense', 'debit'), '2028-01-15', '5555.00', '0');

    $byAccount = $this->getJson("/api/v1/budgeting/versions/{$id}/variance?through_period=2")->assertOk()->json('data');
    $expenseRow = collect($byAccount['rows'])->firstWhere('key', $this->expense->id);
    $revenueRow = collect($byAccount['rows'])->firstWhere('key', $this->revenue->id);

    expect($byAccount['through_period'])->toBe(2)->and($expenseRow['budget'])->toBe('2000.00')->and($expenseRow['actual'])->toBe('2400.00')
        ->and($expenseRow['variance'])->toBe('400.00')->and($expenseRow['variance_percent'])->toBe('20.0')->and($expenseRow['is_favorable'])->toBeFalse()->and($expenseRow['status'])->toBe('over')
        ->and($revenueRow['budget'])->toBe('10000.00')->and($revenueRow['actual'])->toBe('8800.00')->and($revenueRow['variance'])->toBe('-1200.00')->and($revenueRow['is_favorable'])->toBeFalse()
        ->and($byAccount['totals']['budget'])->toBe('12000.00')->and($byAccount['totals']['actual'])->toBe('11200.00')->and($byAccount['totals']['adverse'])->toBe('1600.00');

    $all = $this->getJson("/api/v1/budgeting/versions/{$id}/variance?through_period=12")->json('data');
    expect(collect($all['rows'])->firstWhere('key', $this->expense->id)['actual'])->toBe('12399.00');

    $byCenter = $this->getJson("/api/v1/budgeting/versions/{$id}/variance?through_period=2&group_by=cost_center")->json('data');
    $centerRow = collect($byCenter['rows'])->firstWhere('key', $center->id);
    expect($centerRow['budget'])->toBe('2000.00')->and($centerRow['actual'])->toBe('2000.00')->and($centerRow['code'])->toBe($center->code);

    $byPeriod = $this->getJson("/api/v1/budgeting/versions/{$id}/variance?through_period=3&group_by=period")->json('data');
    expect(array_column($byPeriod['rows'], 'key'))->toBe(['1', '2', '3'])->and($byPeriod['rows'][2]['actual'])->toBe('0.00')->and($byPeriod['rows'][0]['budget'])->toBe('6000.00');

    $this->getJson("/api/v1/budgeting/versions/{$id}/variance?group_by=nonsense")->assertUnprocessable()->assertJsonValidationErrors('group_by');
    $this->getJson("/api/v1/budgeting/versions/{$id}/variance?through_period=13")->assertUnprocessable()->assertJsonValidationErrors('through_period');
});

test('a rolling forecast keeps the actuals of the closed periods and re-spreads the rest', function (): void {
    $version = bdgVersion(['name' => 'Budget 2028']);
    $id = $version['id'];
    $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1200', 'method' => 'even', 'start_period' => 1, 'end_period' => 12])->assertCreated();

    $this->postJson("/api/v1/budgeting/versions/{$id}/reforecast", ['name' => 'RF1', 'from_period' => 3])->assertUnprocessable()->assertJsonValidationErrors('status');
    bdgApprove($id);

    bdgPost($this->tenant, $this->expense, '2028-01-12', '150.00', '0');
    bdgPost($this->tenant, $this->expense, '2028-02-12', '50.00', '0');

    $annual = $this->postJson("/api/v1/budgeting/versions/{$id}/reforecast", ['name' => 'RF annual', 'from_period' => 3])->assertCreated()->json('data');
    expect($annual['version_type'])->toBe('rolling_forecast')->and($annual['status'])->toBe('draft')->and($annual['base_version_id'])->toBe($id)->and($annual['reforecast_from_period'])->toBe(3)->and($annual['lines_count'])->toBe(12);
    $lines = BudgetLine::query()->where('version_id', $annual['id'])->orderBy('period')->get();
    expect($lines->take(2)->pluck('amount')->all())->toBe(['150.00', '50.00'])
        ->and($lines->take(2)->pluck('source')->unique()->values()->all())->toBe(['actual'])
        ->and($lines->skip(2)->pluck('amount')->unique()->values()->all())->toBe(['100.00'])
        ->and($lines->skip(2)->pluck('source')->unique()->values()->all())->toBe(['reforecast'])
        ->and(bdgMoney($lines->sum('amount')))->toBe('1200.00');

    $remaining = $this->postJson("/api/v1/budgeting/versions/{$id}/reforecast", ['name' => 'RF remaining +10', 'from_period' => 3, 'mode' => 'keep_remaining', 'adjustment_percent' => 10])->assertCreated()->json('data');
    $lines = BudgetLine::query()->where('version_id', $remaining['id'])->orderBy('period')->get();
    expect($lines->skip(2)->pluck('amount')->unique()->values()->all())->toBe(['110.00'])->and(bdgMoney($lines->take(2)->sum('amount')))->toBe('200.00');

    bdgPost($this->tenant, $this->expense, '2028-03-12', '2000.00', '0');
    $overspent = $this->postJson("/api/v1/budgeting/versions/{$id}/reforecast", ['name' => 'RF overspent', 'from_period' => 4])->assertCreated()->json('data');
    expect(bdgMoney(BudgetLine::query()->where('version_id', $overspent['id'])->where('period', '>=', 4)->sum('amount')))->toBe('0.00');

    $this->postJson("/api/v1/budgeting/versions/{$id}/reforecast", ['name' => 'RF annual', 'from_period' => 3])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/api/v1/budgeting/versions/{$id}/reforecast", ['name' => 'Bad', 'from_period' => 0, 'mode' => 'magic'])->assertUnprocessable()->assertJsonValidationErrors(['from_period', 'mode']);

    $unlocked = BudgetVersion::query()->findOrFail($annual['id']);
    expect($unlocked->drivers()->count())->toBe(0);
});

test('a forecast starts as a copy of a version with its lines and drivers', function (): void {
    $version = bdgVersion(['name' => 'Budget base']);
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/drivers", ['name' => 'Rent', 'account_id' => $this->expense->id, 'quantity' => '1', 'rate' => '1200', 'spread_method' => 'even', 'start_period' => 1, 'end_period' => 12])->assertCreated();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/generate-drivers")->assertOk();
    bdgApprove($version['id']);

    $copy = $this->postJson("/api/v1/budgeting/versions/{$version['id']}/copy", ['name' => 'Forecast Q2'])->assertCreated()->json('data');
    expect($copy['version_type'])->toBe('forecast')->and($copy['status'])->toBe('draft')->and($copy['lines_count'])->toBe(12)->and($copy['drivers_count'])->toBe(1)->and($copy['total_amount'])->toBe('1200.00');
    $driver = BudgetDriver::query()->where('version_id', $copy['id'])->firstOrFail();
    expect(BudgetLine::query()->where('version_id', $copy['id'])->where('driver_id', $driver->id)->count())->toBe(12);
    bdgLine($copy['id'], $this->expense->id, 1, '1')->assertCreated();

    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/copy", ['name' => 'Forecast Q2'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/copy", ['name' => 'X', 'version_type' => 'rolling_forecast'])->assertUnprocessable()->assertJsonValidationErrors('version_type');
});

test('the dashboard shows versions, budget against actuals and the adverse variances', function (): void {
    $this->getJson('/api/v1/budgeting/dashboard')->assertOk()->assertJsonPath('data.versions.total', 0)->assertJsonPath('data.selected_version', null);

    $version = bdgVersion();
    $id = $version['id'];
    $this->postJson("/api/v1/budgeting/versions/{$id}/spread", ['account_id' => $this->expense->id, 'total_amount' => '1200', 'method' => 'even', 'start_period' => 1, 'end_period' => 12])->assertCreated();
    $this->postJson("/api/v1/budgeting/versions/{$id}/submit")->assertOk();

    $draftView = $this->getJson('/api/v1/budgeting/dashboard')->assertOk()->json('data');
    expect($draftView['pending_approval'][0]['id'])->toBe($id)->and($draftView['versions']['by_status']['submitted'])->toBe(1);

    $this->postJson("/api/v1/budgeting/versions/{$id}/approve")->assertOk();
    bdgPost($this->tenant, $this->expense, '2028-01-10', '150.00', '0');
    bdgPost($this->tenant, $this->expense, '2028-02-10', '120.00', '0');

    $data = $this->getJson('/api/v1/budgeting/dashboard?through_period=2')->assertOk()->json('data');
    expect($data['selected_version']['id'])->toBe($id)
        ->and($data['through_period'])->toBe(2)
        ->and($data['totals']['annual_budget'])->toBe('1200.00')
        ->and($data['totals']['budget'])->toBe('200.00')
        ->and($data['totals']['actual'])->toBe('270.00')
        ->and($data['totals']['variance'])->toBe('70.00')
        ->and($data['totals']['variance_percent'])->toBe('35.0')
        ->and($data['totals']['consumption_percent'])->toBe('22.5')
        ->and($data['series'])->toHaveCount(12)
        ->and($data['series'][1]['cumulative_actual'])->toBe('270.00')
        ->and($data['series'][2]['actual'])->toBeNull()
        ->and($data['series'][11]['cumulative_budget'])->toBe('1200.00')
        ->and($data['top_overruns'][0]['key'])->toBe($this->expense->id);

    $this->getJson('/api/v1/budgeting/dashboard?through_period=0')->assertOk()->assertJsonPath('data.totals.actual', '0.00');
    $this->getJson('/api/v1/budgeting/dashboard?version_id='.Str::uuid())->assertUnprocessable()->assertJsonValidationErrors('version_id');
});

test('budgeting data never crosses company boundaries', function (): void {
    $version = bdgVersion();
    $line = bdgLine($version['id'], $this->expense->id, 1, '10')->json('data');
    $driver = $this->postJson("/api/v1/budgeting/versions/{$version['id']}/drivers", ['name' => 'D', 'account_id' => $this->expense->id, 'quantity' => '1', 'rate' => '1', 'spread_method' => 'even', 'start_period' => 1, 'end_period' => 2])->json('data');

    $other = bdgTenant('Altra');
    bdgAct(bdgMember($other, 'owner'), $other);

    $this->getJson("/api/v1/budgeting/versions/{$version['id']}")->assertNotFound();
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}", ['name' => 'Hijack'])->assertNotFound();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/submit")->assertNotFound();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/copy", ['name' => 'Steal'])->assertNotFound();
    $this->getJson("/api/v1/budgeting/versions/{$version['id']}/variance")->assertNotFound();
    $this->getJson("/api/v1/budgeting/versions/{$version['id']}/lines")->assertNotFound();
    $this->putJson("/api/v1/budgeting/lines/{$line['id']}", ['amount' => '1'])->assertNotFound();
    $this->postJson("/api/v1/budgeting/drivers/{$driver['id']}/generate")->assertNotFound();
    $this->getJson('/api/v1/budgeting/versions')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/budgeting/dashboard?version_id='.$version['id'])->assertUnprocessable()->assertJsonValidationErrors('version_id');
    expect(BudgetVersion::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});
