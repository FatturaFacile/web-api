<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Ana '.Str::random(5), 'legal_name' => 'Ana Srl', 'country' => 'CH', 'default_currency' => 'CHF']);
    aaAct(aaMember($this->tenant, 'owner'), $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function aaMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@analytical-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function aaAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function aaCenter(string $code, string $type, string $category, array $extra = []): string
{
    $response = test()->postJson('/api/v1/analytical/centers', ['code' => $code, 'name' => $code.' name', 'center_type' => $type, 'category' => $category, ...$extra]);
    $response->assertCreated();

    return (string) $response->json('data.id');
}

/**
 * @param  array<string, mixed>  $dimensions
 */
function aaPost(string $element, string $amount, array $dimensions, string $date = '2027-03-15'): void
{
    test()->postJson('/api/v1/analytical/entries', ['posting_date' => $date, 'lines' => [['amount' => $amount, 'cost_element_type' => $element, ...$dimensions]]])->assertCreated();
}

/**
 * Three centers (IT auxiliary, ASM production, PC profit), a month of postings and a one-step schedule on the area driver.
 *
 * @return array<string, string>
 */
function aaScenario(): array
{
    $it = aaCenter('IT', 'COST_CENTER', 'AUXILIARY');
    $asm = aaCenter('ASM', 'COST_CENTER', 'PRODUCTION');
    $pc = aaCenter('PC', 'PROFIT_CENTER', 'PROFIT');
    aaPost('FIXED_DIRECT', '1000.00', ['cost_center_id' => $it]);
    aaPost('VARIABLE_DIRECT', '400.00', ['cost_center_id' => $asm]);
    aaPost('REVENUE', '5000.00', ['profit_center_id' => $pc]);

    $area = collect(test()->getJson('/api/v1/analytical/drivers')->json('data'))->firstWhere('code', 'AREA_SQM')['id'];
    test()->putJson("/api/v1/analytical/drivers/{$area}/values", ['period_key' => '2027-03', 'values' => [['analytical_center_id' => $asm, 'value' => 300], ['analytical_center_id' => $pc, 'value' => 100]]])->assertOk();
    $schedule = test()->postJson('/api/v1/analytical/schedules', ['name' => 'Monthly', 'steps' => [['step_order' => 1, 'source_center_id' => $it, 'driver_type' => 'AREA_SQM', 'allocation_driver_id' => $area]]]);
    $schedule->assertCreated();

    return ['it' => $it, 'asm' => $asm, 'pc' => $pc, 'schedule' => (string) $schedule->json('data.id')];
}

it('builds a center tree, moves a center and refuses a cycle', function (): void {
    $root = aaCenter('ROOT', 'COST_CENTER', 'STRUCTURAL');
    $child = aaCenter('CHILD', 'COST_CENTER', 'AUXILIARY', ['parent_id' => $root, 'manager_name' => 'Rita Rossi']);
    $leaf = aaCenter('LEAF', 'COST_CENTER', 'PRODUCTION', ['parent_id' => $child]);

    $tree = $this->getJson('/api/v1/analytical/centers/tree')->assertOk()->json('data');
    expect($tree)->toHaveCount(1)->and($tree[0]['children'][0]['children'][0]['code'])->toBe('LEAF');
    expect($this->getJson('/api/v1/analytical/centers/flat')->json('data.2.depth'))->toBe(2);
    expect(collect($this->getJson("/api/v1/analytical/centers/{$leaf}/ancestors")->json('data'))->pluck('code')->all())->toEqualCanonicalizing(['ROOT', 'CHILD']);

    $this->putJson("/api/v1/analytical/centers/{$root}", ['parent_id' => $leaf])->assertStatus(422);
    $this->putJson("/api/v1/analytical/centers/{$leaf}", ['parent_id' => $root])->assertOk()->assertJsonPath('data.depth', 1);
});

it('rejects a duplicate code and a profit center under a cost center', function (): void {
    $cost = aaCenter('CC1', 'COST_CENTER', 'AUXILIARY');

    $this->postJson('/api/v1/analytical/centers', ['code' => 'CC1', 'name' => 'dup', 'center_type' => 'COST_CENTER', 'category' => 'AUXILIARY'])->assertStatus(422);
    $this->postJson('/api/v1/analytical/centers', ['code' => 'PC1', 'name' => 'p', 'center_type' => 'PROFIT_CENTER', 'category' => 'PROFIT', 'parent_id' => $cost])->assertStatus(422);
});

it('keeps a center that is referenced by postings and lets an unused one go', function (): void {
    $used = aaCenter('USED', 'COST_CENTER', 'PRODUCTION');
    $free = aaCenter('FREE', 'COST_CENTER', 'PRODUCTION');
    aaPost('FIXED_DIRECT', '10.00', ['cost_center_id' => $used]);

    $this->deleteJson("/api/v1/analytical/centers/{$used}")->assertStatus(422);
    $this->deleteJson("/api/v1/analytical/centers/{$free}")->assertNoContent();
    $this->postJson("/api/v1/analytical/centers/{$used}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
});

it('refuses postings on an inactive center or outside its validity', function (): void {
    $closed = aaCenter('OLD', 'COST_CENTER', 'PRODUCTION', ['valid_to' => '2027-01-31']);

    $this->postJson('/api/v1/analytical/entries', ['posting_date' => '2027-03-15', 'lines' => [['amount' => '5.00', 'cost_element_type' => 'FIXED_DIRECT', 'cost_center_id' => $closed]]])->assertStatus(422);
});

it('splits a line over vectors with exact rounding and keeps the statutory ledger untouched', function (): void {
    $a = aaCenter('A', 'COST_CENTER', 'PRODUCTION');
    $b = aaCenter('B', 'COST_CENTER', 'PRODUCTION');
    $c = aaCenter('C', 'COST_CENTER', 'PRODUCTION');
    $journalsBefore = DB::table('journal_entries')->count();

    $response = $this->postJson('/api/v1/analytical/entries', ['posting_date' => '2027-03-15', 'lines' => [[
        'amount' => '100.00', 'cost_element_type' => 'VARIABLE_DIRECT',
        'vectors' => [['cost_center_id' => $a, 'percentage' => 33.3333], ['cost_center_id' => $b, 'percentage' => 33.3333], ['cost_center_id' => $c, 'percentage' => 33.3334]],
    ]]])->assertCreated();

    $amounts = collect($response->json('data.lines'))->pluck('amount')->map(fn ($v) => (string) $v)->all();
    expect(array_reduce($amounts, static fn (string $carry, string $v): string => bcadd($carry, $v, 4), '0'))->toBe('100.0000')->and($amounts)->toBe(['33.3300', '33.3300', '33.3400']);
    expect(DB::table('journal_entries')->count())->toBe($journalsBefore);
    $this->postJson('/api/v1/analytical/entries', ['posting_date' => '2027-03-15', 'lines' => [['amount' => '100.00', 'cost_element_type' => 'VARIABLE_DIRECT', 'vectors' => [['cost_center_id' => $a, 'percentage' => 60], ['cost_center_id' => $b, 'percentage' => 30]]]]])->assertStatus(422);
});

it('derives dimensions from a rule, lets a manual value win and reports the provenance', function (): void {
    $cc = aaCenter('RULECC', 'COST_CENTER', 'PRODUCTION');
    $account = (string) Str::uuid();
    $this->postJson('/api/v1/analytical/derivation-rules', ['name' => 'Rent', 'priority' => 10, 'account_id' => $account, 'cost_center_id' => $cc, 'cost_element_type' => 'FIXED_DIRECT'])->assertCreated();

    $this->postJson('/api/v1/analytical/derivation-rules/test', ['account_id' => $account])->assertOk()
        ->assertJsonPath('data.dimensions.cost_center_id', $cc)->assertJsonPath('data.source', 'RULE')->assertJsonPath('data.provenance.cost_element_type', 'RULE');
    $this->postJson('/api/v1/analytical/derivation-rules', ['name' => 'Bad', 'priority' => 1, 'cost_center_id' => $cc])->assertStatus(422);

    $entry = $this->postJson('/api/v1/analytical/entries', ['posting_date' => '2027-03-15', 'lines' => [['amount' => '80.00', 'account_id' => $account]]])->assertCreated();
    expect($entry->json('data.lines.0.derivation_source'))->toBe('RULE')->and($entry->json('data.lines.0.cost_center_id'))->toBe($cc);
});

it('previews without writing, consolidates once and reverses with compensating entries', function (): void {
    $ids = aaScenario();
    $entriesBefore = DB::table('analytical_entries')->count();

    $preview = $this->postJson('/api/v1/analytical/runs/preview', ['schedule_id' => $ids['schedule'], 'period_key' => '2027-03'])->assertCreated();
    $preview->assertJsonPath('data.status', 'PREVIEW')->assertJsonPath('data.total_allocated', '1000.0000');
    $allocations = collect($preview->json('data.allocations'))->pluck('allocated_amount', 'target_center_id')->all();
    expect($allocations[$ids['asm']])->toBe('750.0000')->and($allocations[$ids['pc']])->toBe('250.0000');
    expect(DB::table('analytical_entries')->count())->toBe($entriesBefore);

    $runId = $preview->json('data.id');
    $this->postJson("/api/v1/analytical/runs/{$runId}/consolidate")->assertOk()->assertJsonPath('data.status', 'CONSOLIDATED');
    expect(DB::table('analytical_entries')->where('entry_type', 'STEP_DOWN_APPORTIONMENT')->count())->toBe(2);

    $second = $this->postJson('/api/v1/analytical/runs/preview', ['schedule_id' => $ids['schedule'], 'period_key' => '2027-03'])->json('data.id');
    $this->postJson("/api/v1/analytical/runs/{$second}/consolidate")->assertStatus(422);
    $this->postJson('/api/v1/analytical/entries', ['posting_date' => '2027-03-20', 'lines' => [['amount' => '1.00', 'cost_element_type' => 'FIXED_DIRECT', 'cost_center_id' => $ids['it']]]])->assertStatus(422);

    $this->postJson("/api/v1/analytical/runs/{$runId}/reverse", ['reason' => 'Correction'])->assertOk()->assertJsonPath('data.status', 'REVERSED');
    expect(DB::table('analytical_entries')->where('entry_type', 'STEP_DOWN_APPORTIONMENT')->where('is_reversed', true)->count())->toBe(2)
        ->and(DB::table('analytical_entries')->where('entry_type', 'REVERSAL')->count())->toBe(2);
    $this->postJson("/api/v1/analytical/runs/{$runId}/reverse")->assertStatus(422);
});

it('reports the contribution margin levels and the direct versus apportioned comparison', function (): void {
    $ids = aaScenario();
    $runId = $this->postJson('/api/v1/analytical/runs/preview', ['schedule_id' => $ids['schedule'], 'period_key' => '2027-03'])->json('data.id');
    $this->postJson("/api/v1/analytical/runs/{$runId}/consolidate")->assertOk();

    $company = $this->getJson('/api/v1/analytical/reports/contribution-margin?from=2027-03-01&to=2027-03-31')->assertOk()->json('data.statement');
    expect($company)->toMatchArray(['operating_revenues' => '5000.00', 'direct_variable_costs' => '400.00', 'contribution_margin_1' => '4600.00', 'direct_fixed_costs' => '1000.00', 'contribution_margin_2' => '3600.00', 'apportioned_costs' => '0.00', 'operating_result' => '3600.00']);

    $profit = $this->getJson("/api/v1/analytical/reports/contribution-margin?profit_center_id={$ids['pc']}")->assertOk()->json('data.statement');
    expect($profit)->toMatchArray(['operating_revenues' => '5000.00', 'contribution_margin_2' => '5000.00', 'apportioned_costs' => '250.00', 'operating_result' => '4750.00', 'operating_result_pct' => '95.00']);

    $rows = collect($this->getJson('/api/v1/analytical/reports/direct-vs-apportioned')->assertOk()->json('data.rows'))->keyBy('code');
    expect($rows['IT']['direct_cost'])->toBe('1000.00')->and($rows['IT']['apportioned_out'])->toBe('1000.00')->and($rows['IT']['net_cost'])->toBe('0.00')
        ->and($rows['ASM']['apportioned_in'])->toBe('750.00')->and($rows['ASM']['net_cost'])->toBe('1150.00')->and($rows['ASM']['variance'])->toBe('750.00');

    $csv = $this->get('/api/v1/analytical/reports/contribution-margin?format=csv');
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->getContent())->toContain('operating_result');
});

it('excludes reversed entries from the reports', function (): void {
    $ids = aaScenario();
    $runId = $this->postJson('/api/v1/analytical/runs/preview', ['schedule_id' => $ids['schedule'], 'period_key' => '2027-03'])->json('data.id');
    $this->postJson("/api/v1/analytical/runs/{$runId}/consolidate")->assertOk();
    $this->postJson("/api/v1/analytical/runs/{$runId}/reverse")->assertOk();

    $profit = $this->getJson("/api/v1/analytical/reports/contribution-margin?profit_center_id={$ids['pc']}")->json('data.statement');
    expect($profit['apportioned_costs'])->toBe('0.00')->and($profit['operating_result'])->toBe('5000.00');
});

it('rolls a hierarchy up in the margin report', function (): void {
    $parent = aaCenter('PARENT', 'PROFIT_CENTER', 'PROFIT');
    $child = aaCenter('KID', 'PROFIT_CENTER', 'PROFIT', ['parent_id' => $parent]);
    aaPost('REVENUE', '300.00', ['profit_center_id' => $child]);
    aaPost('REVENUE', '100.00', ['profit_center_id' => $parent]);

    $direct = $this->getJson("/api/v1/analytical/reports/contribution-margin?profit_center_id={$parent}")->json('data.statement.operating_revenues');
    $rolled = $this->getJson("/api/v1/analytical/reports/contribution-margin?profit_center_id={$parent}&include_descendants=1")->json('data.statement.operating_revenues');
    expect($direct)->toBe('100.00')->and($rolled)->toBe('400.00');
});

it('validates fixed percentage schedules and rejects a center that sends twice', function (): void {
    $a = aaCenter('SA', 'COST_CENTER', 'AUXILIARY');
    $b = aaCenter('SB', 'COST_CENTER', 'PRODUCTION');

    $this->postJson('/api/v1/analytical/schedules', ['name' => 'Bad', 'steps' => [['step_order' => 1, 'source_center_id' => $a, 'driver_type' => 'FIXED_PERCENTAGE', 'fixed_weights_json' => [$b => 90]]]])->assertStatus(422);
    $this->postJson('/api/v1/analytical/schedules', ['name' => 'Twice', 'steps' => [['step_order' => 1, 'source_center_id' => $a, 'driver_type' => 'AREA_SQM'], ['step_order' => 2, 'source_center_id' => $a, 'driver_type' => 'AREA_SQM']]])->assertStatus(422);
    $this->postJson('/api/v1/analytical/schedules', ['name' => 'Good', 'steps' => [['step_order' => 1, 'source_center_id' => $a, 'driver_type' => 'FIXED_PERCENTAGE', 'fixed_weights_json' => [$b => 100]]]])->assertCreated();
});

it('reverses a manual entry once', function (): void {
    $cc = aaCenter('REV', 'COST_CENTER', 'PRODUCTION');
    $entry = $this->postJson('/api/v1/analytical/entries', ['posting_date' => '2027-04-02', 'lines' => [['amount' => '25.00', 'cost_element_type' => 'FIXED_DIRECT', 'cost_center_id' => $cc]]])->json('data.id');

    $this->postJson("/api/v1/analytical/entries/{$entry}/reverse")->assertCreated()->assertJsonPath('data.entry_type', 'REVERSAL')->assertJsonPath('data.lines.0.amount', '-25.0000');
    $this->postJson("/api/v1/analytical/entries/{$entry}/reverse")->assertStatus(422);
});

it('isolates the data of each tenant and the roles without access', function (): void {
    $id = aaCenter('MINE', 'COST_CENTER', 'PRODUCTION');

    $other = Tenant::create(['name' => 'Other '.Str::random(5), 'legal_name' => 'Other Srl', 'country' => 'CH', 'default_currency' => 'CHF']);
    aaAct(aaMember($other, 'owner'), $other);
    $this->getJson("/api/v1/analytical/centers/{$id}")->assertNotFound();
    expect($this->getJson('/api/v1/analytical/centers')->json('data'))->toBe([]);

    aaAct(aaMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/analytical/centers')->assertForbidden();
});
