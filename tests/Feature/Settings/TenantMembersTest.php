<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
});

function membershipUser(Tenant $tenant, string $role, array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::password(20)]);

    $tenant->users()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    return $user;
}

function platformAdminUser(): User
{
    $user = User::create(['name' => 'Root', 'email' => Str::uuid().'@example.com', 'password' => Str::password(20)]);
    $user->forceFill(['is_platform_admin' => true])->save();

    return $user;
}

function actAs(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

test('an owner adds a registered user with a role and module levels', function (): void {
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);
    $newcomer = User::create(['name' => 'Newcomer', 'email' => 'new@example.com', 'password' => Str::password(20)]);

    $this->postJson('/api/v1/settings/users', ['email' => 'new@example.com', 'role' => 'sales', 'modules' => ['treasury' => 'blocked', 'crm' => 'read']])
        ->assertCreated()
        ->assertJsonPath('data.role', 'sales')
        ->assertJsonPath('data.modules.treasury', 'blocked')
        ->assertJsonPath('data.modules.crm', 'read');

    expect($newcomer->belongsToTenant($this->tenant->id))->toBeTrue();
});

test('adding an unknown email or an existing member is refused', function (): void {
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);
    $member = membershipUser($this->tenant, 'member');

    $this->postJson('/api/v1/settings/users', ['email' => 'nobody@example.com'])->assertUnprocessable();
    $this->postJson('/api/v1/settings/users', ['email' => $member->email])->assertConflict();
});

test('a blocked module is closed to the member whatever the role says', function (): void {
    $member = membershipUser($this->tenant, 'member');
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$member->id}", ['modules' => ['crm' => 'blocked']])->assertOk();

    actAs($member, $this->tenant);
    $this->getJson('/api/v1/crm/clients')->assertForbidden();
    expect($this->getJson('/api/v1/invoicing/invoices')->status())->not->toBe(403);
});

test('a read level keeps the member from writing and a write level opens writing', function (): void {
    $member = membershipUser($this->tenant, 'member');
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$member->id}", ['modules' => ['invoicing' => 'read', 'crm' => 'write']])->assertOk();

    actAs($member, $this->tenant);
    expect($this->getJson('/api/v1/invoicing/invoices')->status())->not->toBe(403);
    $this->postJson('/api/v1/invoicing/invoices', [])->assertForbidden();
    expect($this->postJson('/api/v1/crm/contacts', [])->status())->not->toBe(403);
});

test('the default level clears a module and the other permissions stay', function (): void {
    $member = membershipUser($this->tenant, 'member', ['gl.journals.post', 'deny:crm']);
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$member->id}", ['modules' => ['crm' => 'default']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['gl.journals.post'])
        ->assertJsonPath('data.modules', []);
});

test('the profile lists the permissions of the active membership', function (): void {
    actAs(membershipUser($this->tenant, 'member', ['deny:crm']), $this->tenant);

    $this->getJson('/api/v1/auth/profile')->assertOk()->assertJsonPath('data.permissions', ['deny:crm']);
});

test('members without the system module cannot manage users', function (): void {
    $member = membershipUser($this->tenant, 'member');
    actAs(membershipUser($this->tenant, 'accountant'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$member->id}", ['role' => 'admin'])->assertForbidden();
    $this->postJson('/api/v1/settings/users', ['email' => 'x@example.com'])->assertForbidden();
    $this->deleteJson("/api/v1/settings/users/{$member->id}")->assertForbidden();
    $this->getJson('/api/v1/settings/access-options')->assertForbidden();
});

test('nobody changes their own membership', function (): void {
    $admin = membershipUser($this->tenant, 'admin');
    actAs($admin, $this->tenant);

    $this->putJson("/api/v1/settings/users/{$admin->id}", ['role' => 'member'])->assertConflict();
    $this->deleteJson("/api/v1/settings/users/{$admin->id}")->assertConflict();
});

test('only an owner grants the owner role or touches an owner', function (): void {
    $owner = membershipUser($this->tenant, 'owner');
    $member = membershipUser($this->tenant, 'member');
    actAs(membershipUser($this->tenant, 'admin'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$member->id}", ['role' => 'owner'])->assertForbidden();
    $this->putJson("/api/v1/settings/users/{$owner->id}", ['role' => 'member'])->assertForbidden();
    $this->deleteJson("/api/v1/settings/users/{$owner->id}")->assertForbidden();
});

test('the last owner cannot be demoted or removed', function (): void {
    $lastOwner = membershipUser($this->tenant, 'owner');
    $root = platformAdminUser();
    $this->tenant->users()->attach($root->id, ['id' => (string) Str::uuid(), 'role' => 'admin', 'permissions' => json_encode([])]);
    actAs($root, $this->tenant);

    $this->putJson("/api/v1/settings/users/{$lastOwner->id}", ['role' => 'member'])->assertConflict();
    $this->deleteJson("/api/v1/settings/users/{$lastOwner->id}")->assertConflict();
});

test('an owner is not locked out by a deny permission', function (): void {
    actAs(membershipUser($this->tenant, 'owner', ['deny:system']), $this->tenant);

    $this->getJson('/api/v1/settings/access-options')->assertOk();
});

test('an unknown role, module or level is rejected', function (): void {
    $member = membershipUser($this->tenant, 'member');
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$member->id}", ['role' => 'wizard'])->assertUnprocessable();
    $this->putJson("/api/v1/settings/users/{$member->id}", ['modules' => ['nope' => 'read']])->assertUnprocessable();
    $this->putJson("/api/v1/settings/users/{$member->id}", ['modules' => ['crm' => 'god']])->assertUnprocessable();
});

test('a member of another tenant cannot be managed', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $stranger = membershipUser($other, 'member');
    actAs(membershipUser($this->tenant, 'owner'), $this->tenant);

    $this->putJson("/api/v1/settings/users/{$stranger->id}", ['role' => 'admin'])->assertNotFound();
    $this->deleteJson("/api/v1/settings/users/{$stranger->id}")->assertNotFound();
});

test('a platform administrator grants, changes and removes the access to a tenant', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $user = membershipUser($this->tenant, 'member');
    $admin = platformAdminUser();
    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/admin/users/{$user->id}/tenants/{$other->id}", ['role' => 'accountant', 'modules' => ['hr' => 'blocked']])->assertOk();
    $this->getJson("/api/v1/admin/users/{$user->id}/tenants")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonFragment(['tenant_id' => $other->id, 'role' => 'accountant']);

    $this->putJson("/api/v1/admin/users/{$user->id}/tenants/{$other->id}", ['role' => 'admin'])->assertOk();
    $this->deleteJson("/api/v1/admin/users/{$user->id}/tenants/{$other->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/users?search='.urlencode($admin->email))->assertOk()->assertJsonFragment(['email' => $admin->email]);
    $this->getJson('/api/v1/admin/tenants?search=Beta')->assertOk()->assertJsonFragment(['name' => 'Beta']);
});

test('the platform administration of tenant access is closed to tenant users', function (): void {
    $user = membershipUser($this->tenant, 'owner');
    actAs($user, $this->tenant);

    $this->getJson('/api/v1/admin/users')->assertForbidden();
    $this->putJson("/api/v1/admin/users/{$user->id}/tenants/{$this->tenant->id}", [])->assertForbidden();
});
