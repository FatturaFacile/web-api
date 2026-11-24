<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Platform\Identity\Infrastructure\Models\Role;

uses(RefreshDatabase::class);

function iamUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::uuid().'@example.com', 'password' => Str::password(20)]);
    $tenant->users()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function iamAct(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    Artisan::call('permissions:sync');
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->owner = iamUser($this->tenant, 'owner');
    iamAct($this->owner, $this->tenant);
});

test('permissions:sync seeds the system roles and is idempotent', function (): void {
    Artisan::call('permissions:sync');

    expect(Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(12);
    $this->getJson('/api/v1/settings/roles')->assertOk()->assertJsonCount(12, 'data');
});

test('an owner creates, updates and deletes a custom role', function (): void {
    $id = $this->postJson('/api/v1/settings/roles', ['name' => 'Cashier', 'permissions' => ['invoicing.invoices.view']])
        ->assertCreated()->assertJsonPath('data.permissions.0', 'invoicing.invoices.view')->json('data.id');

    $this->putJson("/api/v1/settings/roles/{$id}", ['permissions' => ['invoicing.invoices.view', 'crm.contacts.view']])
        ->assertOk()->assertJsonCount(2, 'data.permissions');

    $this->deleteJson("/api/v1/settings/roles/{$id}")->assertOk();
});

test('unknown permissions and system role edits are refused with 409', function (): void {
    $this->postJson('/api/v1/settings/roles', ['name' => 'Bad', 'permissions' => ['crm.contacts.fly']])->assertStatus(409);

    $system = Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'member')->firstOrFail();
    $this->putJson("/api/v1/settings/roles/{$system->id}", ['name' => 'Renamed'])->assertStatus(409);
});

test('a role in use cannot be deleted', function (): void {
    $member = iamUser($this->tenant, 'member');
    $id = $this->postJson('/api/v1/settings/roles', ['name' => 'Cashier', 'permissions' => ['crm.contacts.view']])->json('data.id');
    $this->putJson("/api/v1/settings/users/{$member->id}/roles", ['role_ids' => [$id]])->assertOk();

    $this->deleteJson("/api/v1/settings/roles/{$id}")->assertStatus(409);
});

test('regression #230: a member is denied the role console and the catalog', function (): void {
    iamAct(iamUser($this->tenant, 'member'), $this->tenant);

    $this->getJson('/api/v1/settings/roles')->assertForbidden();
    $this->getJson('/api/v1/settings/permissions/catalog')->assertForbidden();
    $this->postJson('/api/v1/settings/roles', ['name' => 'X', 'permissions' => []])->assertForbidden();
});

test('a role granted through an assignment opens exactly its permissions', function (): void {
    $member = iamUser($this->tenant, 'member');
    $id = $this->postJson('/api/v1/settings/roles', ['name' => 'Role editor', 'permissions' => ['settings.roles.view']])->json('data.id');
    $this->putJson("/api/v1/settings/users/{$member->id}/roles", ['role_ids' => [$id]])->assertOk();

    iamAct($member, $this->tenant);
    $this->getJson('/api/v1/settings/roles')->assertOk();
    $this->postJson('/api/v1/settings/roles', ['name' => 'Y', 'permissions' => []])->assertForbidden();
    $this->getJson('/api/v1/profile/permissions')->assertOk()->assertJsonFragment(['settings.roles.view']);
});

test('nobody changes their own roles and the last owner stays', function (): void {
    $any = Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'viewer')->firstOrFail();

    $this->putJson("/api/v1/settings/users/{$this->owner->id}/roles", ['role_ids' => [$any->id]])->assertForbidden();

    $admin = iamUser($this->tenant, 'admin');
    iamAct($admin, $this->tenant);
    $this->putJson("/api/v1/settings/users/{$this->owner->id}/roles", ['role_ids' => [$any->id]])->assertStatus(409);
});

test('tenant B cannot see or touch the roles of tenant A', function (): void {
    $id = $this->postJson('/api/v1/settings/roles', ['name' => 'Secret', 'permissions' => ['crm.contacts.view']])->json('data.id');

    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    iamAct(iamUser($other, 'owner'), $other);

    $this->getJson("/api/v1/settings/roles/{$id}")->assertNotFound();
    $this->deleteJson("/api/v1/settings/roles/{$id}")->assertNotFound();
    expect(collect($this->getJson('/api/v1/settings/roles')->json('data'))->pluck('name'))->not->toContain('Secret');
});

test('ABAC and field policies are saved and listed; unsupported attributes are rejected', function (): void {
    $id = $this->postJson('/api/v1/settings/roles', ['name' => 'Limited', 'permissions' => ['invoicing.invoices.approve']])->json('data.id');

    $this->putJson("/api/v1/settings/roles/{$id}/abac-rules", ['rules' => [[
        'permission' => 'invoicing.invoices.approve', 'effect' => 'deny',
        'conditions' => [['attribute' => 'document.total', 'operator' => '>', 'value' => 10000]],
    ]]])->assertOk();
    $this->getJson("/api/v1/settings/roles/{$id}/abac-rules")->assertOk()->assertJsonPath('data.0.effect', 'deny');

    $this->putJson("/api/v1/settings/roles/{$id}/abac-rules", ['rules' => [[
        'permission' => 'x.y.view', 'effect' => 'deny', 'conditions' => [['attribute' => 'user.password', 'operator' => '==', 'value' => 'a']],
    ]]])->assertUnprocessable();

    $this->putJson("/api/v1/settings/roles/{$id}/field-policies", ['policies' => [['resource' => 'employee', 'field' => 'iban', 'visibility' => 'masked', 'kind' => 'iban']]])->assertOk();
    $this->getJson("/api/v1/settings/roles/{$id}/field-policies")->assertOk()->assertJsonPath('data.0.visibility', 'masked');
});
