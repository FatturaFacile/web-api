<?php

declare(strict_types=1);

use App\Mail\TenantInvitationMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->owner = accessUser($this->tenant, 'owner');
    actAsAccessMember($this->owner, $this->tenant);
});

function accessUser(Tenant $tenant, string $role, ?string $email = null): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => $email ?? Str::uuid().'@example.com', 'password' => Str::password(20)]);
    $tenant->users()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function actAsAccessMember(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

function membership(Tenant $tenant, User $user): object
{
    return DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $user->id)->first();
}

// ---------------------------------------------------------------------------------------------------------------------
// Suspend / reactivate
// ---------------------------------------------------------------------------------------------------------------------

test('an owner suspends and reactivates a member, who is locked out while suspended', function (): void {
    $member = accessUser($this->tenant, 'member');

    $this->postJson("/api/v1/settings/users/{$member->id}/suspend")
        ->assertOk()
        ->assertJsonPath('data.id', $member->id)
        ->assertJsonPath('data.suspended', true);
    expect(membership($this->tenant, $member)->suspended_at)->not->toBeNull();

    actAsAccessMember($member, $this->tenant);
    $this->getJson('/api/v1/settings/tenant')->assertStatus(403);

    actAsAccessMember($this->owner, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$member->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.suspended', false);
    expect(membership($this->tenant, $member)->suspended_at)->toBeNull();
});

test('nobody suspends themselves, the last owner, or an owner without being one', function (): void {
    $this->postJson("/api/v1/settings/users/{$this->owner->id}/suspend")->assertConflict();

    $admin = accessUser($this->tenant, 'admin');
    actAsAccessMember($admin, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$this->owner->id}/suspend")->assertForbidden();

    $secondOwner = accessUser($this->tenant, 'owner');
    actAsAccessMember($secondOwner, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$this->owner->id}/suspend")->assertOk();
    $this->postJson('/api/v1/settings/users/'.Str::uuid().'/suspend')->assertNotFound();
});

test('suspending needs the system module', function (): void {
    $member = accessUser($this->tenant, 'member');
    $other = accessUser($this->tenant, 'member');
    actAsAccessMember($member, $this->tenant);

    $this->postJson("/api/v1/settings/users/{$other->id}/suspend")->assertForbidden();
    $this->getJson('/api/v1/settings/invitations')->assertForbidden();
    $this->getJson('/api/v1/settings/access/roles')->assertForbidden();
});

test('a reactivated member needs room in the plan', function (): void {
    $member = accessUser($this->tenant, 'member');
    $this->postJson("/api/v1/settings/users/{$member->id}/suspend")->assertOk();
    $this->tenant->forceFill(['max_users' => 1])->save();

    $this->postJson("/api/v1/settings/users/{$member->id}/reactivate")->assertUnprocessable()->assertJsonValidationErrors('users');
});

// ---------------------------------------------------------------------------------------------------------------------
// Invitations
// ---------------------------------------------------------------------------------------------------------------------

test('an invitation is sent, listed, renewed for the same address, resent and revoked', function (): void {
    Mail::fake();

    $id = $this->postJson('/api/v1/settings/invitations', ['email' => 'New.Person@Example.com', 'role' => 'accountant', 'modules' => ['crm' => 'read']])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new.person@example.com')
        ->assertJsonPath('data.role', 'accountant')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.modules.crm', 'read')
        ->assertJsonMissingPath('data.token_hash')
        ->json('data.id');
    Mail::assertQueued(TenantInvitationMail::class, 1);

    $this->postJson('/api/v1/settings/invitations', ['email' => 'new.person@example.com', 'role' => 'sales'])->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.role', 'sales');
    $this->getJson('/api/v1/settings/invitations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.invited_by', $this->owner->name);

    $this->postJson("/api/v1/settings/invitations/{$id}/resend")->assertOk()->assertJsonPath('data.status', 'pending');
    Mail::assertQueued(TenantInvitationMail::class, 3);

    $this->deleteJson("/api/v1/settings/invitations/{$id}")->assertOk();
    $this->getJson('/api/v1/settings/invitations')->assertJsonPath('data.0.status', 'revoked');
    $this->postJson("/api/v1/settings/invitations/{$id}/resend")->assertConflict();
});

test('an invitation refuses a member, an unknown role and the owner role', function (): void {
    Mail::fake();
    $member = accessUser($this->tenant, 'member');

    $this->postJson('/api/v1/settings/invitations', ['email' => $member->email])->assertConflict();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'x@example.com', 'role' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->postJson('/api/v1/settings/invitations', ['email' => 'x@example.com', 'role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('role');
    Mail::assertNothingQueued();
});

test('invitations count against the plan and an expired one stops counting', function (): void {
    Mail::fake();
    $this->tenant->forceFill(['plan_code' => 'starter'])->save(); // 3 people

    $this->postJson('/api/v1/settings/invitations', ['email' => 'a@example.com'])->assertCreated();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'b@example.com'])->assertCreated();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'c@example.com'])->assertUnprocessable()->assertJsonValidationErrors('users');

    $this->travel(8)->days();
    $this->getJson('/api/v1/settings/invitations')->assertJsonPath('data.0.status', 'expired');
    $this->getJson('/api/v1/settings/access/limits')->assertJsonPath('data.pending_invitations', 0);
    $this->postJson('/api/v1/settings/invitations', ['email' => 'c@example.com'])->assertCreated();
});

test('a person without an account accepts through the public link, with a name and a password', function (): void {
    Mail::fake();
    $team = $this->postJson('/api/v1/settings/access/teams', ['name' => 'Sales'])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/settings/invitations', ['email' => 'fresh@example.com', 'role' => 'sales', 'team_ids' => [$team]])->assertCreated();

    $url = null;
    Mail::assertQueued(TenantInvitationMail::class, function (TenantInvitationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $token = $query['token'];

    app('auth')->forgetGuards();
    $this->withHeader('X-Tenant-Id', '')->getJson("/api/v1/auth/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.email', 'fresh@example.com')
        ->assertJsonPath('data.tenant_name', 'Alfa')
        ->assertJsonPath('data.has_account', false);

    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'password']);
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'Fresh Person', 'password' => 'Str0ng!Passw0rd#1', 'password_confirmation' => 'Str0ng!Passw0rd#1'])
        ->assertOk()
        ->assertJsonPath('data.tenant_name', 'Alfa');

    $user = User::where('email', 'fresh@example.com')->firstOrFail();
    expect(membership($this->tenant, $user)->role)->toBe('sales')
        ->and(DB::table('access_team_members')->where('user_id', $user->id)->where('team_id', $team)->exists())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    // The link works once.
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [])->assertNotFound();
});

test('an existing account accepts only while signed in with it', function (): void {
    Mail::fake();
    $account = User::create(['name' => 'Existing', 'email' => 'existing@example.com', 'password' => Str::password(20)]);
    $this->postJson('/api/v1/settings/invitations', ['email' => 'existing@example.com', 'role' => 'accountant'])->assertCreated();

    $url = null;
    Mail::assertQueued(TenantInvitationMail::class, function (TenantInvitationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $token = $query['token'];

    app('auth')->forgetGuards();
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('data.has_account', true);
    $this->postJson("/api/v1/auth/invitations/{$token}/accept")->assertUnauthorized();

    // Somebody else's session is not enough.
    Sanctum::actingAs(accessUser($this->tenant, 'member'), [], 'sanctum');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept")->assertForbidden();

    Sanctum::actingAs($account, [], 'sanctum');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept")->assertOk()->assertJsonPath('data.email', 'existing@example.com');
    expect(membership($this->tenant, $account)->role)->toBe('accountant');
});

test('a revoked or unknown token is not found', function (): void {
    Mail::fake();
    $id = $this->postJson('/api/v1/settings/invitations', ['email' => 'gone@example.com'])->json('data.id');
    $this->deleteJson("/api/v1/settings/invitations/{$id}")->assertOk();

    $this->getJson('/api/v1/auth/invitations/'.Str::random(48))->assertNotFound();
    $this->deleteJson('/api/v1/settings/invitations/'.Str::uuid())->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// Custom roles and the matrix
// ---------------------------------------------------------------------------------------------------------------------

test('a custom role is created, listed, assigned to a member and rewritten for its holders', function (): void {
    $role = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Shop floor lead', 'base_role' => 'member', 'modules' => ['crm' => 'blocked', 'invoicing' => 'default']])
        ->assertCreated()
        ->assertJsonPath('data.key', 'shop_floor_lead')
        ->assertJsonPath('data.base_role', 'member')
        ->assertJsonPath('data.modules.crm', 'blocked')
        ->assertJsonMissingPath('data.modules.invoicing')
        ->assertJsonPath('data.members', 0)
        ->json('data');

    $member = accessUser($this->tenant, 'accountant');
    $this->putJson("/api/v1/settings/users/{$member->id}", ['custom_role_id' => $role['id']])
        ->assertOk()
        ->assertJsonPath('data.role', 'member')
        ->assertJsonPath('data.custom_role_id', $role['id'])
        ->assertJsonPath('data.modules.crm', 'blocked');

    $overview = $this->getJson('/api/v1/settings/access/roles')->assertOk();
    expect($overview->json('data.roles.0.members'))->toBe(1)
        ->and($overview->json('data.base_roles'))->not->toContain('owner')
        ->and($overview->json('data.modules'))->toContain('crm')
        ->and($overview->json('data.levels'))->toBe(['default', 'blocked', 'read', 'write']);

    $this->putJson("/api/v1/settings/access/roles/{$role['id']}", ['base_role' => 'sales', 'modules' => ['treasury' => 'read']])
        ->assertOk()
        ->assertJsonPath('data.base_role', 'sales')
        ->assertJsonPath('data.members', 1);

    $this->getJson('/api/v1/settings/users')->assertOk();
    $row = collect($this->getJson('/api/v1/settings/users')->json('data'))->firstWhere('id', $member->id);
    expect($row['role'])->toBe('sales')
        ->and($row['modules'])->toBe(['treasury' => 'read'])
        ->and($row['custom_role_id'])->toBe($role['id']);

    actAsAccessMember($member, $this->tenant);
    expect($this->getJson('/api/v1/crm/clients')->status())->not->toBe(403);
});

test('a role alone detaches a member from the custom role and clears the levels it set', function (): void {
    $role = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Closed CRM', 'base_role' => 'member', 'modules' => ['crm' => 'blocked']])->json('data');
    $member = accessUser($this->tenant, 'member');
    $this->putJson("/api/v1/settings/users/{$member->id}", ['custom_role_id' => $role['id'], 'modules' => ['treasury' => 'blocked']])->assertOk();

    $this->putJson("/api/v1/settings/users/{$member->id}", ['role' => 'sales'])
        ->assertOk()
        ->assertJsonPath('data.role', 'sales')
        ->assertJsonPath('data.custom_role_id', null)
        ->assertJsonPath('data.modules.treasury', 'blocked')
        ->assertJsonMissingPath('data.modules.crm');
});

test('a custom role in use cannot be deleted, an unused one can', function (): void {
    $role = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Temp', 'base_role' => 'member'])->json('data');
    $member = accessUser($this->tenant, 'member');
    $this->putJson("/api/v1/settings/users/{$member->id}", ['custom_role_id' => $role['id']])->assertOk();

    $this->deleteJson("/api/v1/settings/access/roles/{$role['id']}")->assertConflict();

    $this->putJson("/api/v1/settings/users/{$member->id}", ['custom_role_id' => null])->assertOk()->assertJsonPath('data.custom_role_id', null);
    $this->deleteJson("/api/v1/settings/access/roles/{$role['id']}")->assertOk();
    $this->getJson('/api/v1/settings/access/roles')->assertJsonCount(0, 'data.roles');
    $this->deleteJson("/api/v1/settings/access/roles/{$role['id']}")->assertNotFound();
});

test('a custom role is validated and limited by the plan', function (): void {
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'base_role']);
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Bad', 'base_role' => 'owner'])->assertUnprocessable()->assertJsonValidationErrors('base_role');
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Bad', 'base_role' => 'member', 'modules' => ['nope' => 'read']])->assertUnprocessable()->assertJsonValidationErrors('modules.nope');
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Bad', 'base_role' => 'member', 'modules' => ['crm' => 'sideways']])->assertUnprocessable()->assertJsonValidationErrors('modules.crm');

    $this->tenant->forceFill(['max_custom_roles' => 1])->save();
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'One', 'base_role' => 'member'])->assertCreated();
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Two', 'base_role' => 'member'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'One', 'base_role' => 'member'])->assertUnprocessable();
});

test('the roles of one tenant are not seen or assigned from another', function (): void {
    $role = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Mine', 'base_role' => 'member'])->json('data');

    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $betaOwner = accessUser($other, 'owner');
    actAsAccessMember($betaOwner, $other);

    $this->getJson('/api/v1/settings/access/roles')->assertJsonCount(0, 'data.roles');
    $this->putJson("/api/v1/settings/access/roles/{$role['id']}", ['name' => 'Stolen'])->assertNotFound();
    $betaMember = accessUser($other, 'member');
    $this->putJson("/api/v1/settings/users/{$betaMember->id}", ['custom_role_id' => $role['id']])->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
});

test('the matrix lists system and custom roles with what each can read and write', function (): void {
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Locked treasury', 'base_role' => 'accountant', 'modules' => ['treasury' => 'blocked', 'crm' => 'read']])->assertCreated();

    $matrix = $this->getJson('/api/v1/settings/access/matrix')->assertOk()->json('data');
    $roles = collect($matrix['roles'])->keyBy('key');

    expect($matrix['modules'])->toContain('treasury')
        ->and($roles['accountant']['custom'])->toBeFalse()
        ->and($roles['accountant']['access']['treasury'])->toBe(['read' => true, 'write' => true])
        ->and($roles['member']['access']['treasury'])->toBe(['read' => false, 'write' => false])
        ->and($roles['locked_treasury']['custom'])->toBeTrue()
        ->and($roles['locked_treasury']['base_role'])->toBe('accountant')
        ->and($roles['locked_treasury']['access']['treasury'])->toBe(['read' => false, 'write' => false])
        ->and($roles['locked_treasury']['access']['crm'])->toBe(['read' => true, 'write' => false])
        ->and($roles['locked_treasury'])->toHaveKey('id');
});

// ---------------------------------------------------------------------------------------------------------------------
// Teams
// ---------------------------------------------------------------------------------------------------------------------

test('a team is created, updated, filled with members and deleted', function (): void {
    $team = $this->postJson('/api/v1/settings/access/teams', ['name' => 'Support', 'description' => 'Helpdesk'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Support')
        ->assertJsonPath('data.member_ids', [])
        ->json('data');
    $a = accessUser($this->tenant, 'member');
    $b = accessUser($this->tenant, 'member');

    $this->putJson("/api/v1/settings/access/teams/{$team['id']}", ['name' => 'Customer support'])->assertOk()->assertJsonPath('data.name', 'Customer support');
    $this->putJson("/api/v1/settings/access/teams/{$team['id']}/members", ['user_ids' => [$a->id, $b->id]])->assertOk()->assertJsonCount(2, 'data.member_ids');
    $this->putJson("/api/v1/settings/access/teams/{$team['id']}/members", ['user_ids' => [$b->id]])->assertOk()->assertJsonPath('data.member_ids', [$b->id]);

    $this->getJson('/api/v1/settings/access/teams')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.member_ids', [$b->id]);
    $row = collect($this->getJson('/api/v1/settings/users')->json('data'))->firstWhere('id', $b->id);
    expect($row['team_ids'])->toBe([$team['id']]);

    $this->deleteJson("/api/v1/settings/access/teams/{$team['id']}")->assertOk();
    $this->getJson('/api/v1/settings/access/teams')->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/settings/access/teams/{$team['id']}", ['name' => 'Gone'])->assertNotFound();
});

test('team members must belong to the tenant and the team needs a name', function (): void {
    $team = $this->postJson('/api/v1/settings/access/teams', ['name' => 'Ops'])->json('data');
    $outsider = User::create(['name' => 'Outsider', 'email' => 'out@example.com', 'password' => Str::password(20)]);

    $this->putJson("/api/v1/settings/access/teams/{$team['id']}/members", ['user_ids' => [$outsider->id]])->assertUnprocessable()->assertJsonPath('invalid_params.0.name', 'user_ids');
    $this->putJson("/api/v1/settings/access/teams/{$team['id']}/members", [])->assertUnprocessable();
    $this->postJson('/api/v1/settings/access/teams', [])->assertUnprocessable()->assertJsonValidationErrors('name');
});

test('a team with a custom role gives it to the plain members that join', function (): void {
    $role = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Seller', 'base_role' => 'sales'])->json('data');
    $team = $this->postJson('/api/v1/settings/access/teams', ['name' => 'Sales', 'custom_role_id' => $role['id']])->assertCreated()->assertJsonPath('data.custom_role_id', $role['id'])->json('data');
    $plain = accessUser($this->tenant, 'member');
    $accountant = accessUser($this->tenant, 'accountant');

    $this->putJson("/api/v1/settings/access/teams/{$team['id']}/members", ['user_ids' => [$plain->id, $accountant->id]])->assertOk();

    expect(membership($this->tenant, $plain)->custom_role_id)->toBe($role['id'])
        ->and(membership($this->tenant, $plain)->role)->toBe('sales')
        ->and(membership($this->tenant, $accountant)->custom_role_id)->toBeNull()
        ->and(membership($this->tenant, $accountant)->role)->toBe('accountant');
});

// ---------------------------------------------------------------------------------------------------------------------
// Delegations
// ---------------------------------------------------------------------------------------------------------------------

test('a delegate acts with the access of the delegator during the delegation only', function (): void {
    $admin = accessUser($this->tenant, 'admin');
    $delegate = accessUser($this->tenant, 'member');

    actAsAccessMember($delegate, $this->tenant);
    $this->getJson('/api/v1/settings/users')->assertForbidden();

    actAsAccessMember($admin, $this->tenant);
    $delegation = $this->postJson('/api/v1/settings/access/delegations', [
        'delegate_id' => $delegate->id,
        'starts_at' => now()->addDay()->toIso8601String(),
        'ends_at' => now()->addDays(3)->toIso8601String(),
        'reason' => 'Holiday',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'scheduled')
        ->assertJsonPath('data.delegator.id', $admin->id)
        ->assertJsonPath('data.delegate.id', $delegate->id)
        ->assertJsonPath('data.role', 'admin')
        ->json('data');

    actAsAccessMember($delegate, $this->tenant);
    $this->getJson('/api/v1/settings/users')->assertForbidden();

    $this->travel(2)->days();
    $this->getJson('/api/v1/settings/users')->assertOk();

    actAsAccessMember($admin, $this->tenant);
    $this->getJson('/api/v1/settings/access/delegations')->assertOk()->assertJsonPath('data.0.status', 'active');
    $this->deleteJson("/api/v1/settings/access/delegations/{$delegation['id']}")->assertOk();
    $this->getJson('/api/v1/settings/access/delegations')->assertJsonPath('data.0.status', 'revoked');

    actAsAccessMember($delegate, $this->tenant);
    $this->getJson('/api/v1/settings/users')->assertForbidden();
});

test('a delegation may be limited to some modules and ends on its date', function (): void {
    $delegate = accessUser($this->tenant, 'member');
    $this->postJson('/api/v1/settings/access/delegations', [
        'delegate_id' => $delegate->id,
        'starts_at' => now()->subHour()->toIso8601String(),
        'ends_at' => now()->addDay()->toIso8601String(),
        'modules' => ['treasury'],
    ])->assertCreated()->assertJsonPath('data.modules', ['treasury']);

    actAsAccessMember($delegate, $this->tenant);
    expect($this->getJson('/api/v1/treasury/bank-accounts')->status())->not->toBe(403);
    $this->getJson('/api/v1/settings/users')->assertForbidden();

    $this->travel(2)->days();
    $this->getJson('/api/v1/treasury/bank-accounts')->assertForbidden();
});

test('a delegation is validated and cannot be given on behalf of others by a plain member', function (): void {
    $member = accessUser($this->tenant, 'member');
    $other = accessUser($this->tenant, 'member');
    $outsider = User::create(['name' => 'Outsider', 'email' => 'o@example.com', 'password' => Str::password(20)]);
    $window = ['starts_at' => now()->toIso8601String(), 'ends_at' => now()->addDay()->toIso8601String()];

    $this->postJson('/api/v1/settings/access/delegations', ['delegate_id' => $member->id, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    $this->postJson('/api/v1/settings/access/delegations', ['delegate_id' => $this->owner->id, ...$window])->assertUnprocessable();
    $this->postJson('/api/v1/settings/access/delegations', ['delegate_id' => $outsider->id, ...$window])->assertUnprocessable();
    $this->postJson('/api/v1/settings/access/delegations', ['delegate_id' => $member->id, 'delegator_id' => $member->id, ...$window])->assertUnprocessable();
    $this->postJson('/api/v1/settings/access/delegations', ['delegate_id' => $member->id, 'modules' => ['nope'], ...$window])->assertUnprocessable()->assertJsonValidationErrors('modules.0');

    // An owner delegates on behalf of another member.
    $this->postJson('/api/v1/settings/access/delegations', ['delegate_id' => $member->id, 'delegator_id' => $other->id, ...$window])->assertCreated()->assertJsonPath('data.delegator.id', $other->id);
});

// ---------------------------------------------------------------------------------------------------------------------
// Limits, audit and plan
// ---------------------------------------------------------------------------------------------------------------------

test('the limits show the plan, the usage and the catalogue of plans', function (): void {
    accessUser($this->tenant, 'member');
    $suspended = accessUser($this->tenant, 'member');
    $this->postJson("/api/v1/settings/users/{$suspended->id}/suspend")->assertOk();
    $this->tenant->forceFill(['plan_code' => 'standard', 'max_users' => null])->save();

    $limits = $this->getJson('/api/v1/settings/access/limits')->assertOk()->json('data');

    expect($limits)->toMatchArray(['plan_code' => 'standard', 'max_users' => 10, 'active_users' => 2, 'pending_invitations' => 0, 'max_custom_roles' => 3, 'custom_roles' => 0])
        ->and(collect($limits['plans'])->pluck('code')->all())->toBe(['starter', 'standard', 'professional', 'enterprise']);

    $this->tenant->forceFill(['plan_code' => 'enterprise', 'max_users' => 5])->save();
    expect($this->getJson('/api/v1/settings/access/limits')->json('data.max_users'))->toBe(5);
});

test('every access change reaches the audit history, filtered by type and paginated', function (): void {
    Mail::fake();
    $member = accessUser($this->tenant, 'member');
    $this->postJson("/api/v1/settings/users/{$member->id}/suspend")->assertOk();
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Audited', 'base_role' => 'member'])->assertCreated();
    $this->postJson('/api/v1/settings/access/teams', ['name' => 'Audited team'])->assertCreated();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'audit@example.com'])->assertCreated();

    $all = $this->getJson('/api/v1/settings/access/audit')->assertOk()->assertJsonPath('meta.total', 4)->json();
    expect(collect($all['data'])->pluck('event')->all())->toBe(['access.invitation.sent', 'access.team.created', 'access.role.created', 'access.member.suspended'])
        ->and($all['data'][3])->toMatchArray(['type' => 'access.member', 'subject_id' => $member->id, 'old_values' => ['suspended' => false], 'new_values' => ['suspended' => true]])
        ->and($all['data'][3]['actor']['id'])->toBe($this->owner->id)
        ->and(json_encode($all))->not->toContain('audit@example.com');

    $this->getJson('/api/v1/settings/access/audit?type=access.role')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'access.role');
    $this->getJson('/api/v1/settings/access/audit?per_page=5&page=1')->assertJsonPath('meta.per_page', 5)->assertJsonPath('meta.last_page', 1);
    $this->getJson('/api/v1/settings/access/audit?type=nonsense')->assertUnprocessable();

    // Another tenant sees nothing of it.
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    actAsAccessMember(accessUser($other, 'owner'), $other);
    $this->getJson('/api/v1/settings/access/audit')->assertJsonPath('meta.total', 0);
});

test('a platform administrator sets the plan of a tenant and its limits', function (): void {
    $root = User::create(['name' => 'Root', 'email' => Str::uuid().'@example.com', 'password' => Str::password(20)]);
    $root->forceFill(['is_platform_admin' => true])->save();
    Sanctum::actingAs($root);

    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan_code' => 'starter', 'max_users' => 7, 'max_custom_roles' => null])
        ->assertOk()
        ->assertJsonPath('data.plan_code', 'starter')
        ->assertJsonPath('data.max_users', 7)
        ->assertJsonPath('data.max_custom_roles', 0)
        ->assertJsonPath('data.active_users', 1);

    $fresh = $this->tenant->fresh();
    expect($fresh->plan_code)->toBe('starter')->and($fresh->max_users)->toBe(7)->and($fresh->max_custom_roles)->toBeNull();

    // A field that is absent keeps its value; null removes an override.
    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['max_users' => null])->assertOk()->assertJsonPath('data.max_users', 3)->assertJsonPath('data.plan_code', 'starter');

    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan_code' => 'gold'])->assertUnprocessable()->assertJsonValidationErrors('plan_code');
    $this->putJson('/api/v1/admin/tenants/'.Str::uuid().'/plan', ['plan_code' => 'standard'])->assertNotFound();

    Sanctum::actingAs($root);
    $ledger = DB::table('enterprise_audit_ledger')->where('tenant_id', $this->tenant->id)->where('action', 'ACCESS.PLAN.CHANGED')->count();
    expect($ledger)->toBe(2);
});

test('only a platform administrator may set a plan', function (): void {
    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan_code' => 'starter'])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// CSV import
// ---------------------------------------------------------------------------------------------------------------------

function csvFile(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('users.csv', $content);
}

test('an import checks the file first and then adds members and invites the others', function (): void {
    Mail::fake();
    $existing = User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => Str::password(20)]);
    $member = accessUser($this->tenant, 'member', 'already@example.com');
    $team = $this->postJson('/api/v1/settings/access/teams', ['name' => 'Sales'])->json('data.id');

    $csv = implode("\n", [
        'email;name;role;team',
        'anna@example.com;Anna;accountant;Sales',
        'new@example.com;New;sales;Sales',
        'already@example.com;Already;member;',
        'broken;Broken;member;',
        'bad.role@example.com;X;wizard;',
        'bad.team@example.com;X;member;Nowhere',
        'NEW@example.com;Repeated;member;',
    ]);

    $dry = $this->post('/api/v1/settings/users/import', ['file' => csvFile($csv), 'dry_run' => '1', 'default_role' => 'member'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($dry)->toMatchArray(['dry_run' => true, 'total' => 7, 'created' => 1, 'invited' => 1, 'skipped' => 2, 'errors' => 3])
        ->and(collect($dry['rows'])->pluck('status')->all())->toBe(['would_add', 'would_invite', 'skipped', 'error', 'error', 'error', 'skipped'])
        ->and($dry['rows'][0])->toMatchArray(['line' => 2, 'email' => 'anna@example.com'])
        ->and($dry['rows'][3]['message'])->not->toBeNull();
    expect($existing->belongsToTenant($this->tenant->id))->toBeFalse();
    Mail::assertNothingQueued();

    $real = $this->post('/api/v1/settings/users/import', ['file' => csvFile($csv), 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect($real)->toMatchArray(['dry_run' => false, 'created' => 1, 'invited' => 1, 'skipped' => 2, 'errors' => 3])
        ->and(collect($real['rows'])->pluck('status')->all())->toBe(['created', 'invited', 'skipped', 'error', 'error', 'error', 'skipped']);

    expect(membership($this->tenant, $existing)->role)->toBe('accountant')
        ->and(DB::table('access_team_members')->where('user_id', $existing->id)->where('team_id', $team)->exists())->toBeTrue();
    Mail::assertQueued(TenantInvitationMail::class, 1);
    $this->getJson('/api/v1/settings/invitations')->assertJsonCount(1, 'data')->assertJsonPath('data.0.team_ids', [$team]);
    expect($member->fresh())->not->toBeNull();
});

test('an import stops at the plan limit and refuses unusable files', function (): void {
    Mail::fake();
    $this->tenant->forceFill(['max_users' => 2])->save();

    $report = $this->post('/api/v1/settings/users/import', ['file' => csvFile("email,role\na@example.com,member\nb@example.com,member\n"), 'dry_run' => '0'], ['Accept' => 'application/json'])->assertOk()->json('data');
    expect(collect($report['rows'])->pluck('status')->all())->toBe(['invited', 'error']);

    $this->post('/api/v1/settings/users/import', ['file' => csvFile("name;role\nx;member\n"), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('invalid_params.0.name', 'file');
    $this->post('/api/v1/settings/users/import', ['dry_run' => '1'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post('/api/v1/settings/users/import', ['file' => UploadedFile::fake()->create('users.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
});

// ---------------------------------------------------------------------------------------------------------------------
// User interface preferences
// ---------------------------------------------------------------------------------------------------------------------

test('a user reads default preferences and changes them one field at a time', function (): void {
    $member = accessUser($this->tenant, 'member');
    actAsAccessMember($member, $this->tenant);

    $this->getJson('/api/v1/user/preferences')->assertOk()->assertExactJson([
        'success' => true,
        'message' => 'Preferences retrieved',
        'data' => ['theme_preference' => 'system', 'ui_density' => 'comfortable', 'reduced_motion' => null],
    ]);

    $this->patchJson('/api/v1/user/preferences', ['theme_preference' => 'dark'])->assertOk()->assertJsonPath('data.theme_preference', 'dark')->assertJsonPath('data.ui_density', 'comfortable');
    $this->patchJson('/api/v1/user/preferences', ['ui_density' => 'compact', 'reduced_motion' => true])->assertOk()
        ->assertJsonPath('data.theme_preference', 'dark')->assertJsonPath('data.ui_density', 'compact')->assertJsonPath('data.reduced_motion', true);
    $this->patchJson('/api/v1/user/preferences', ['reduced_motion' => null])->assertOk()->assertJsonPath('data.reduced_motion', null);

    $this->getJson('/api/v1/user/preferences')->assertJsonPath('data.theme_preference', 'dark')->assertJsonPath('data.ui_density', 'compact');
});

test('preferences belong to the user, not to the tenant, and are validated', function (): void {
    $this->patchJson('/api/v1/user/preferences', ['theme_preference' => 'neon'])->assertUnprocessable()->assertJsonValidationErrors('theme_preference');
    $this->patchJson('/api/v1/user/preferences', ['ui_density' => 'tiny'])->assertUnprocessable()->assertJsonValidationErrors('ui_density');
    $this->patchJson('/api/v1/user/preferences', ['reduced_motion' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('reduced_motion');
    $this->patchJson('/api/v1/user/preferences', ['theme_preference' => 'light'])->assertOk();

    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $other->users()->attach($this->owner->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode([])]);
    actAsAccessMember($this->owner, $other);
    $this->getJson('/api/v1/user/preferences')->assertJsonPath('data.theme_preference', 'light');

    $stranger = accessUser($this->tenant, 'member');
    actAsAccessMember($stranger, $this->tenant);
    $this->getJson('/api/v1/user/preferences')->assertJsonPath('data.theme_preference', 'system');
});
