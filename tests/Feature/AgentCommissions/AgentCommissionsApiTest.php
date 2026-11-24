<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Models\FiscalParameter;

require_once __DIR__.'/AgentCommissionsSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    agcSetUp($this);
});

it('requires authentication', function (): void {
    $this->refreshApplication();

    $this->withHeader('X-Tenant-Id', (string) $this->tenant->id)->getJson('/api/v1/agent-commissions/agents')->assertUnauthorized();
});

it('lets the finance and sales-manager roles read and closes the module to the other members', function (string $role, int $status): void {
    treasuryActor($this, $role);

    foreach (['agents', 'rules', 'zones', 'ledger', 'statements', 'lookups', 'fiscal-parameters', 'enasarco/overview'] as $path) {
        $this->getJson("/api/v1/agent-commissions/{$path}")->assertStatus($status);
    }
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'accountant' => ['accountant', 200],
    'read-only accountant' => ['accountant_readonly', 200],
    'sales manager' => ['sales_manager', 200],
    'sales' => ['sales', 403],
    'plain member' => ['member', 403],
]);

it('keeps the release of the money to the finance roles: a sales manager sets conditions but cannot approve, pay or override fiscal parameters', function (): void {
    agcAgent($this);
    agcRule($this, 'General', ['rate' => '10']);
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $statementId = $this->postJson('/api/v1/agent-commissions/statements', ['agent_profile_id' => AgentProfile::query()->value('id'), 'period_start' => '2027-01-01', 'period_end' => '2027-03-31'])->assertCreated()->json('data.id');

    treasuryActor($this, 'sales_manager');
    $newRep = agcRepresentative($this, 'Nuovo Agente', 'nuovo@example.com');
    $this->postJson('/api/v1/agent-commissions/agents', ['sales_representative_id' => $newRep->id, 'agent_type' => 'direct', 'fiscal_regime' => 'none'])->assertCreated();
    $this->postJson("/api/v1/agent-commissions/statements/{$statementId}/approve")->assertForbidden();
    $this->postJson("/api/v1/agent-commissions/statements/{$statementId}/pay", [])->assertForbidden();
    $this->putJson('/api/v1/agent-commissions/fiscal-parameters', ['code' => 'vat_rate', 'value' => 10])->assertForbidden();
    $this->get('/api/v1/agent-commissions/exports/cu?year=2027')->assertForbidden();

    treasuryActor($this, 'accountant_readonly');
    $this->postJson('/api/v1/agent-commissions/agents', [])->assertForbidden();
    $this->getJson("/api/v1/agent-commissions/statements/{$statementId}")->assertOk();
});

it('lets a member with the settle grant approve a statement', function (): void {
    agcAgent($this);
    agcRule($this, 'General', ['rate' => '10']);
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $statementId = $this->postJson('/api/v1/agent-commissions/statements', ['agent_profile_id' => AgentProfile::query()->value('id'), 'period_start' => '2027-01-01', 'period_end' => '2027-03-31'])->assertCreated()->json('data.id');

    $member = User::create(['name' => 'grant', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['agent_commissions.write', 'agent_commissions.settle'])]);
    treasuryActAs($this, $member);

    $this->postJson("/api/v1/agent-commissions/statements/{$statementId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

it('creates, updates and deletes an agent profile and validates the contract conditions', function (): void {
    $rep = agcRepresentative($this, 'Anna Agente', 'anna@example.com', '4');

    $this->getJson('/api/v1/agent-commissions/representatives/available')->assertOk()->assertJsonFragment(['id' => $rep->id]);

    $id = $this->postJson('/api/v1/agent-commissions/agents', [
        'sales_representative_id' => $rep->id, 'agent_type' => 'multi_mandate', 'fiscal_regime' => 'enasarco', 'accrual_basis' => 'collected',
        'default_rate' => 4, 'tax_code' => 'NNAGNT80A41F205Z', 'contract_start' => '2027-01-01',
    ])->assertCreated()->assertJsonPath('data.agent_type', 'multi_mandate')->assertJsonPath('data.representative.name', 'Anna Agente')->json('data.id');

    $this->getJson('/api/v1/agent-commissions/representatives/available')->assertOk()->assertJsonMissing(['id' => $rep->id]);
    $this->postJson('/api/v1/agent-commissions/agents', ['sales_representative_id' => $rep->id, 'agent_type' => 'direct', 'fiscal_regime' => 'none'])->assertStatus(422);
    $this->postJson('/api/v1/agent-commissions/agents', ['sales_representative_id' => agcRepresentative($this, 'X', null)->id, 'agent_type' => 'nonsense', 'fiscal_regime' => 'none'])->assertStatus(422);

    $this->putJson("/api/v1/agent-commissions/agents/{$id}", ['accrual_basis' => 'invoiced', 'firr_enabled' => false])->assertOk()->assertJsonPath('data.accrual_basis', 'invoiced')->assertJsonPath('data.firr_enabled', false);
    $this->getJson("/api/v1/agent-commissions/agents/{$id}")->assertOk()->assertJsonPath('data.tax_code', 'NNAGNT80A41F205Z');

    $this->deleteJson("/api/v1/agent-commissions/agents/{$id}")->assertOk();
    expect(AgentProfile::query()->count())->toBe(0);
});

it('only lets a sub-agent have a parent that is not itself a sub-agent', function (): void {
    $parent = agcAgent($this, null, ['agent_type' => 'direct']);
    $subRep = agcRepresentative($this, 'Sub', 'sub@example.com');

    $this->postJson('/api/v1/agent-commissions/agents', ['sales_representative_id' => $subRep->id, 'agent_type' => 'direct', 'fiscal_regime' => 'none', 'parent_agent_profile_id' => $parent->id, 'parent_split_mode' => 'additional_rate', 'parent_split_value' => 1])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_parent');

    $this->postJson('/api/v1/agent-commissions/agents', ['sales_representative_id' => $subRep->id, 'agent_type' => 'sub_agent', 'fiscal_regime' => 'enasarco', 'parent_agent_profile_id' => $parent->id])
        ->assertStatus(422); // the split mode and value are required with a parent

    $this->postJson('/api/v1/agent-commissions/agents', ['sales_representative_id' => $subRep->id, 'agent_type' => 'sub_agent', 'fiscal_regime' => 'enasarco', 'parent_agent_profile_id' => $parent->id, 'parent_split_mode' => 'share_of_commission', 'parent_split_value' => 20])
        ->assertCreated()->assertJsonPath('data.parent_name', $parent->representative->name);
});

it('manages rules with progressive tiers and rejects a tier table with a gap', function (): void {
    $agent = agcAgent($this);
    $payload = ['agent_profile_id' => $agent->id, 'name' => 'Tiered', 'rate_type' => 'percentage', 'priority' => 50, 'tiers' => [
        ['threshold_from' => 0, 'threshold_to' => 10000, 'rate' => 2],
        ['threshold_from' => 10000, 'threshold_to' => null, 'rate' => 3],
    ]];

    $id = $this->postJson('/api/v1/agent-commissions/rules', $payload)->assertCreated()->assertJsonCount(2, 'data.tiers')->json('data.id');

    $gap = $payload;
    $gap['tiers'][1]['threshold_from'] = 12000;
    $this->postJson('/api/v1/agent-commissions/rules', $gap)->assertStatus(422)->assertJsonPath('code', 'invalid_tiers');
    $this->postJson('/api/v1/agent-commissions/rules', ['name' => 'No rate', 'rate_type' => 'percentage'])->assertStatus(422)->assertJsonPath('code', 'rate_required');
    $this->postJson('/api/v1/agent-commissions/rules', ['name' => 'Fixed without amount', 'rate_type' => 'fixed'])->assertStatus(422)->assertJsonPath('code', 'rate_required');

    $this->putJson("/api/v1/agent-commissions/rules/{$id}", ['name' => 'Tiered v2', 'tiers' => [['threshold_from' => 0, 'threshold_to' => null, 'rate' => 4]]])
        ->assertOk()->assertJsonPath('data.name', 'Tiered v2')->assertJsonCount(1, 'data.tiers');

    $this->getJson('/api/v1/agent-commissions/rules?agent_profile_id='.$agent->id)->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/agent-commissions/rules/{$id}")->assertOk();
    expect(CommissionRule::query()->count())->toBe(0);
});

it('simulates which rule wins', function (): void {
    $agent = agcAgent($this, null, ['default_rate' => '4']);
    $category = agcCategory($this, 'Linea');
    $rule = agcRule($this, 'Line rule', ['rate' => '6', 'product_category_id' => $category->id]);

    $this->getJson('/api/v1/agent-commissions/rules/resolve?agent_profile_id='.$agent->id.'&product_category_id='.$category->id)
        ->assertOk()->assertJsonPath('data.rule.id', $rule->id)->assertJsonPath('data.fallback_rate', null);

    $this->getJson('/api/v1/agent-commissions/rules/resolve?agent_profile_id='.$agent->id)
        ->assertOk()->assertJsonPath('data.rule', null)->assertJsonPath('data.fallback_rate', '4.0000');
});

it('manages zones', function (): void {
    $id = $this->postJson('/api/v1/agent-commissions/zones', ['code' => 'TI', 'name' => 'Ticino', 'country' => 'CH', 'regions' => ['TI']])->assertCreated()->assertJsonPath('data.regions', ['TI'])->json('data.id');

    $this->postJson('/api/v1/agent-commissions/zones', ['code' => 'TI', 'name' => 'Duplicate'])->assertStatus(422);
    $this->putJson("/api/v1/agent-commissions/zones/{$id}", ['name' => 'Canton Ticino', 'is_active' => false])->assertOk()->assertJsonPath('data.name', 'Canton Ticino')->assertJsonPath('data.is_active', false);
    $this->getJson('/api/v1/agent-commissions/zones')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/agent-commissions/zones/{$id}")->assertOk();
});

it('serves the seeded statutory parameters and lets a tenant override and restore them', function (): void {
    $list = $this->getJson('/api/v1/agent-commissions/fiscal-parameters')->assertOk();
    $codes = collect($list->json('data'))->pluck('code')->unique()->all();
    expect($codes)->toContain('enasarco_agent_rate', 'enasarco_company_rate', 'enasarco_massimale', 'enasarco_minimale', 'firr_brackets', 'withholding_rate', 'vat_rate')
        ->and(collect($list->json('data'))->every(fn (array $row): bool => $row['is_override'] === false))->toBeTrue();

    $id = $this->putJson('/api/v1/agent-commissions/fiscal-parameters', ['code' => 'vat_rate', 'value' => 21])->assertOk()->assertJsonPath('data.is_override', true)->json('data.id');
    $this->putJson('/api/v1/agent-commissions/fiscal-parameters', ['code' => 'vat_rate', 'value' => 20])->assertOk()->assertJsonPath('data.id', $id);
    $this->putJson('/api/v1/agent-commissions/fiscal-parameters', ['code' => 'not_a_parameter', 'value' => 1])->assertStatus(422);

    $this->deleteJson("/api/v1/agent-commissions/fiscal-parameters/{$id}")->assertOk();
    expect(FiscalParameter::query()->where('code', 'vat_rate')->whereNotNull('tenant_id')->count())->toBe(0);

    $global = FiscalParameter::query()->whereNull('tenant_id')->value('id');
    $this->deleteJson("/api/v1/agent-commissions/fiscal-parameters/{$global}")->assertNotFound();
});

it('uses the tenant override of a parameter in the next statement', function (): void {
    agcAgent($this);
    agcRule($this, 'General', ['rate' => '10']);
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $this->putJson('/api/v1/agent-commissions/fiscal-parameters', ['code' => 'vat_rate', 'value' => 10])->assertOk();

    $this->postJson('/api/v1/agent-commissions/statements', ['agent_profile_id' => AgentProfile::query()->value('id'), 'period_start' => '2027-01-01', 'period_end' => '2027-03-31'])
        ->assertCreated()->assertJsonPath('data.vat_amount', '10.00');
});

it('does not show the agents, rules, ledger or statements of another company', function (): void {
    $agent = agcAgent($this);
    agcRule($this, 'General', ['rate' => '10']);
    agcInvoice($this, [['net' => '1000.00']], '2027-03-10');
    $statementId = $this->postJson('/api/v1/agent-commissions/statements', ['agent_profile_id' => $agent->id, 'period_start' => '2027-01-01', 'period_end' => '2027-03-31'])->assertCreated()->json('data.id');

    $other = Tenant::create(['name' => 'Altra Srl', 'legal_name' => 'Altra Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    treasuryActor($this, 'accountant', $other);

    $this->getJson('/api/v1/agent-commissions/agents')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/agent-commissions/rules')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/agent-commissions/ledger')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/agent-commissions/agents/{$agent->id}")->assertNotFound();
    $this->getJson("/api/v1/agent-commissions/statements/{$statementId}")->assertNotFound();
    $this->postJson("/api/v1/agent-commissions/statements/{$statementId}/approve")->assertNotFound();
    // The statutory defaults are global: every company reads them.
    expect(count($this->getJson('/api/v1/agent-commissions/fiscal-parameters')->assertOk()->json('data')))->toBeGreaterThan(0);
});

it('serves the enumerations of the module', function (): void {
    $this->getJson('/api/v1/agent-commissions/lookups')->assertOk()
        ->assertJsonPath('data.agent_types', ['direct', 'sub_agent', 'multi_mandate', 'single_mandate'])
        ->assertJsonPath('data.accrual_bases', ['invoiced', 'collected'])
        ->assertJsonPath('data.statement_statuses', ['draft', 'approved', 'invoiced', 'paid', 'void']);
});
