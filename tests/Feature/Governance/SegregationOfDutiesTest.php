<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Governance\Models\GovSodRule;
use Modules\Governance\Services\SodRuleCatalog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-07-21 09:00:00');
    $this->tenant = sodTenant('Sod Rossi');
    $this->owner = sodMember($this->tenant, 'owner');
    $this->buyer = sodMember($this->tenant, 'purchasing');
    sodAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function sodTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

/**
 * @param  list<string>  $permissions
 */
function sodMember(Tenant $tenant, string $role, array $permissions = []): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@sod-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    return $user;
}

function sodAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function sodRule(string $severity = 'block'): string
{
    return test()->postJson('/api/v1/governance/sod/rules', [
        'code' => 'SOD-T'.random_int(10, 99),
        'title' => 'Procure and pay',
        'duties_a' => ['procurement.write'],
        'duties_b' => ['treasury.write'],
        'severity' => $severity,
    ])->assertCreated()->json('data.id');
}

test('the standard rules are adopted once and the catalog uses valid severities', function (): void {
    $this->postJson('/api/v1/governance/sod/rules/seed-defaults')->assertOk()->assertJsonPath('data.created', count(SodRuleCatalog::all()));
    $this->postJson('/api/v1/governance/sod/rules/seed-defaults')->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', count(SodRuleCatalog::all()));

    expect(GovSodRule::query()->where('tenant_id', $this->tenant->id)->count())->toBe(count(SodRuleCatalog::all()));
    expect(collect(SodRuleCatalog::all())->pluck('severity')->unique()->diff(GovSodRule::Severities))->toBeEmpty();
});

test('a rule is validated, unique by code and its code cannot change', function (): void {
    $this->postJson('/api/v1/governance/sod/rules', ['code' => 'bad code', 'title' => 'x', 'duties_a' => [], 'duties_b' => ['a']])->assertUnprocessable();

    $id = sodRule();
    $this->postJson('/api/v1/governance/sod/rules', ['code' => GovSodRule::query()->findOrFail($id)->code, 'title' => 'Again', 'duties_a' => ['a.write'], 'duties_b' => ['b.write']])->assertUnprocessable();
    $this->putJson("/api/v1/governance/sod/rules/{$id}", ['code' => 'SOD-NEW'])->assertUnprocessable();
    $this->putJson("/api/v1/governance/sod/rules/{$id}", ['severity' => 'warn', 'is_active' => false])->assertOk()->assertJsonPath('data.severity', 'warn')->assertJsonPath('data.is_active', false);
    $this->deleteJson("/api/v1/governance/sod/rules/{$id}")->assertNoContent();
    $this->getJson('/api/v1/governance/sod/rules')->assertOk()->assertJsonCount(0, 'data');
});

test('the check endpoint reports the conflicts of a role with permissions without saving anything', function (): void {
    sodRule();

    $this->postJson('/api/v1/governance/sod/check', ['role' => 'purchasing', 'permissions' => ['treasury.write']])
        ->assertOk()
        ->assertJsonPath('data.blocked', true)
        ->assertJsonPath('data.conflicts.0.duty_a', 'procurement.write')
        ->assertJsonPath('data.conflicts.0.duty_b', 'treasury.write');
    $this->postJson('/api/v1/governance/sod/check', ['role' => 'purchasing', 'permissions' => ['treasury.write', 'deny:treasury']])->assertOk()->assertJsonPath('data.blocked', false);
    $this->postJson('/api/v1/governance/sod/check', ['role' => 'admin'])->assertOk()->assertJsonPath('data.conflicts', []);
    $this->postJson('/api/v1/governance/sod/check', ['role' => 'purchasing', 'permissions' => ['*']])->assertOk()->assertJsonPath('data.blocked', true);
});

test('a blocking rule refuses the role assignment and an active exception allows it', function (): void {
    $ruleId = sodRule('block');

    $this->putJson("/api/v1/settings/users/{$this->buyer->id}", ['modules' => ['treasury' => 'write']])
        ->assertStatus(409)
        ->assertJsonPath('title', 'Segregation of Duties Conflict')
        ->assertJsonPath('invalid_params.0.reason', 'procurement.write / treasury.write');
    expect($this->buyer->tenants()->first()->pivot->permissions)->not->toContain('treasury.write');

    $this->postJson('/api/v1/governance/sod/exceptions', ['rule_id' => $ruleId, 'user_id' => $this->buyer->id, 'justification' => 'Temporary cover during the audit', 'expires_at' => '2027-08-21'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.approved_by', $this->owner->id);

    $this->putJson("/api/v1/settings/users/{$this->buyer->id}", ['modules' => ['treasury' => 'write']])->assertOk();
    $this->getJson('/api/v1/governance/sod/violations?status=excepted')->assertOk()->assertJsonCount(1, 'data');
});

test('a warning rule allows the assignment and records an open violation that resolves with the conflict', function (): void {
    sodRule('warn');

    $this->putJson("/api/v1/settings/users/{$this->buyer->id}", ['modules' => ['treasury' => 'write']])->assertOk();
    $this->getJson('/api/v1/governance/sod/violations?status=open')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $this->buyer->id)->assertJsonPath('data.0.severity', 'warn');

    $this->putJson("/api/v1/settings/users/{$this->buyer->id}", ['modules' => ['treasury' => 'blocked']])->assertOk();
    $this->getJson('/api/v1/governance/sod/violations?status=open')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/governance/sod/violations?status=resolved')->assertOk()->assertJsonCount(1, 'data');
});

test('the scan finds existing conflicts and an expired exception opens the violation again', function (): void {
    $ruleId = sodRule('block');
    $risky = sodMember($this->tenant, 'purchasing', ['treasury.write']);
    sodMember($this->tenant, 'member');

    $this->postJson('/api/v1/governance/sod/scan')->assertOk()->assertJsonPath('data.open', 1);

    $this->postJson('/api/v1/governance/sod/exceptions', ['rule_id' => $ruleId, 'user_id' => $risky->id, 'justification' => 'Small team without alternatives', 'expires_at' => '2027-07-25'])->assertCreated();
    $this->postJson('/api/v1/governance/sod/scan')->assertOk()->assertJsonPath('data.open', 0)->assertJsonPath('data.excepted', 1);

    $this->travelTo('2027-07-27 09:00:00');
    $this->getJson('/api/v1/governance/sod/exceptions?active=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/governance/sod/exceptions')->assertOk()->assertJsonPath('data.0.status', 'expired');
    $this->postJson('/api/v1/governance/sod/scan')->assertOk()->assertJsonPath('data.open', 1)->assertJsonPath('data.excepted', 0);
});

test('an exception cannot be granted to its own approver, revoked ones stop counting, and members of other companies are refused', function (): void {
    $ruleId = sodRule('block');
    $payload = ['rule_id' => $ruleId, 'justification' => 'Needed during the close', 'expires_at' => '2027-08-01'];

    $this->postJson('/api/v1/governance/sod/exceptions', $payload + ['user_id' => $this->owner->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');

    $stranger = sodMember(sodTenant('Other Co'), 'purchasing');
    $this->postJson('/api/v1/governance/sod/exceptions', $payload + ['user_id' => $stranger->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->postJson('/api/v1/governance/sod/exceptions', array_merge($payload, ['user_id' => $this->buyer->id, 'expires_at' => '2029-01-01']))->assertUnprocessable()->assertJsonValidationErrors('expires_at');

    $id = $this->postJson('/api/v1/governance/sod/exceptions', $payload + ['user_id' => $this->buyer->id])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/governance/sod/exceptions/{$id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
    $this->putJson("/api/v1/settings/users/{$this->buyer->id}", ['modules' => ['treasury' => 'write']])->assertStatus(409);
});

test('rules, violations and exceptions stay inside the company and need the right role', function (): void {
    $ruleId = sodRule('warn');
    $other = sodTenant('Other Co');
    $otherOwner = sodMember($other, 'owner');
    $accountant = sodMember($this->tenant, 'accountant');
    $member = sodMember($this->tenant, 'member');

    sodAct($otherOwner, $other);
    $this->getJson('/api/v1/governance/sod/rules')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/governance/sod/rules/{$ruleId}", ['severity' => 'block'])->assertNotFound();
    $this->deleteJson("/api/v1/governance/sod/rules/{$ruleId}")->assertNotFound();

    sodAct($member, $this->tenant);
    $this->getJson('/api/v1/governance/sod/rules')->assertForbidden();
    $this->getJson('/api/v1/governance/sod/violations')->assertForbidden();
    $this->postJson('/api/v1/governance/sod/scan')->assertForbidden();

    sodAct($accountant, $this->tenant);
    $this->getJson('/api/v1/governance/sod/rules')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/governance/sod/exceptions', ['rule_id' => $ruleId, 'user_id' => $this->buyer->id, 'justification' => 'Not allowed for accountants', 'expires_at' => '2027-08-01'])->assertForbidden();
});
