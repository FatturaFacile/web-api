<?php

declare(strict_types=1);

use App\Mail\TenantInvitationMail;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA', 'plan_code' => 'enterprise']);
});

function accessMember(Tenant $tenant, string $role, array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::password(20)]);
    $tenant->users()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    return $user;
}

function accessActAs(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

test('an admin creates a custom role and assigns it without technical help', function (): void {
    accessActAs(accessMember($this->tenant, 'owner'), $this->tenant);
    $colleague = accessMember($this->tenant, 'member');

    $roleId = $this->postJson('/api/v1/settings/access/roles', [
        'name' => 'Sales auditor',
        'base_role' => 'member',
        'modules' => ['crm' => 'read', 'treasury' => 'blocked'],
    ])->assertCreated()->assertJsonPath('data.key', 'sales_auditor')->json('data.id');

    $this->putJson("/api/v1/settings/users/{$colleague->id}", ['custom_role_id' => $roleId])
        ->assertOk()
        ->assertJsonPath('data.custom_role_id', $roleId)
        ->assertJsonPath('data.modules.crm', 'read')
        ->assertJsonPath('data.modules.treasury', 'blocked');

    // Editing the role reaches the members that hold it.
    $this->putJson("/api/v1/settings/access/roles/{$roleId}", ['modules' => ['crm' => 'write']])->assertOk();

    $member = collect($this->getJson('/api/v1/settings/users')->json('data'))->firstWhere('id', $colleague->id);
    expect($member['modules'])->toBe(['crm' => 'write']);

    // A role in use cannot be deleted.
    $this->deleteJson("/api/v1/settings/access/roles/{$roleId}")->assertStatus(409);
});

test('the permission matrix lists system and custom roles by module and action', function (): void {
    accessActAs(accessMember($this->tenant, 'admin'), $this->tenant);

    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Reader', 'base_role' => 'member', 'modules' => ['treasury' => 'read']])->assertCreated();

    $matrix = $this->getJson('/api/v1/settings/access/matrix')->assertOk()->json('data');
    $custom = collect($matrix['roles'])->firstWhere('key', 'reader');

    expect($matrix['modules'])->toContain('crm', 'treasury')
        ->and($custom['access']['treasury'])->toBe(['read' => true, 'write' => false])
        ->and(collect($matrix['roles'])->firstWhere('key', 'owner')['access']['crm']['write'])->toBeTrue();
});

test('members without the system module cannot manage access', function (): void {
    accessActAs(accessMember($this->tenant, 'member'), $this->tenant);

    $this->getJson('/api/v1/settings/access/roles')->assertForbidden();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'x@example.com'])->assertForbidden();
});

test('an invitation by email creates the account with the chosen role', function (): void {
    Mail::fake();
    accessActAs(accessMember($this->tenant, 'owner'), $this->tenant);

    $this->postJson('/api/v1/settings/invitations', ['email' => 'New.Person@Example.com', 'role' => 'accountant', 'modules' => ['crm' => 'blocked']])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.email', 'new.person@example.com');

    $url = null;
    Mail::assertQueued(TenantInvitationMail::class, function (TenantInvitationMail $mail) use (&$url): bool {
        $url = $mail->url;

        return $mail->hasTo('new.person@example.com');
    });
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);
    $token = $query['token'];

    $this->app['auth']->forgetGuards();
    $this->withoutHeader('Authorization');

    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('data.has_account', false)->assertJsonPath('data.tenant_name', 'Alfa');
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'New Person'])->assertUnprocessable();
    $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'New Person', 'password' => 'Str0ng!Passw0rd#42', 'password_confirmation' => 'Str0ng!Passw0rd#42'])->assertOk();

    $user = User::where('email', 'new.person@example.com')->firstOrFail();
    $membership = $this->tenant->users()->where('users.id', $user->id)->first()->pivot;
    expect($membership->role)->toBe('accountant')->and($user->email_verified_at)->not->toBeNull();

    // The link works only once.
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound();
});

test('an invitation can be revoked and sent again', function (): void {
    Mail::fake();
    accessActAs(accessMember($this->tenant, 'admin'), $this->tenant);

    $id = $this->postJson('/api/v1/settings/invitations', ['email' => 'later@example.com'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/settings/invitations/{$id}/resend")->assertOk();
    Mail::assertQueued(TenantInvitationMail::class, 2);

    $this->deleteJson("/api/v1/settings/invitations/{$id}")->assertOk();
    $this->postJson("/api/v1/settings/invitations/{$id}/resend")->assertStatus(409);
    expect(collect($this->getJson('/api/v1/settings/invitations')->json('data'))->firstWhere('id', $id)['status'])->toBe('revoked');
});

test('the subscription plan limits users, invitations and custom roles', function (): void {
    Mail::fake();
    $this->tenant->forceFill(['max_users' => 2, 'max_custom_roles' => 1])->save();
    accessActAs(accessMember($this->tenant, 'owner'), $this->tenant);

    $this->postJson('/api/v1/settings/invitations', ['email' => 'one@example.com'])->assertCreated();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'two@example.com'])->assertStatus(409);

    $this->postJson('/api/v1/settings/access/roles', ['name' => 'First', 'base_role' => 'member'])->assertCreated();
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Second', 'base_role' => 'member'])->assertStatus(409);

    $this->getJson('/api/v1/settings/access/limits')->assertOk()
        ->assertJsonPath('data.max_users', 2)->assertJsonPath('data.active_users', 1)->assertJsonPath('data.pending_invitations', 1);
});

test('a platform administrator moves a tenant to another plan', function (): void {
    $admin = User::create(['name' => 'Root', 'email' => Str::uuid().'@example.com', 'password' => Str::password(20)]);
    $admin->forceFill(['is_platform_admin' => true])->save();
    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['plan_code' => 'starter'])->assertOk()->assertJsonPath('data.max_users', 3);
    $this->putJson("/api/v1/admin/tenants/{$this->tenant->id}/plan", ['max_users' => 7])->assertOk()->assertJsonPath('data.max_users', 7);
});

test('a member can be deactivated and reactivated', function (): void {
    accessActAs(accessMember($this->tenant, 'owner'), $this->tenant);
    $colleague = accessMember($this->tenant, 'accountant');

    $this->postJson("/api/v1/settings/users/{$colleague->id}/suspend")->assertOk()->assertJsonPath('data.suspended', true);
    expect($colleague->fresh()->belongsToTenant($this->tenant->id))->toBeFalse();

    $this->postJson("/api/v1/settings/users/{$colleague->id}/reactivate")->assertOk()->assertJsonPath('data.suspended', false);
    expect($colleague->fresh()->belongsToTenant($this->tenant->id))->toBeTrue();
});

test('a team gives its custom role to its members', function (): void {
    accessActAs(accessMember($this->tenant, 'owner'), $this->tenant);
    $colleague = accessMember($this->tenant, 'member');

    $roleId = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Buyers', 'base_role' => 'member', 'modules' => ['procurement' => 'write']])->json('data.id');
    $teamId = $this->postJson('/api/v1/settings/access/teams', ['name' => 'Purchasing', 'custom_role_id' => $roleId])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/settings/access/teams/{$teamId}/members", ['user_ids' => [$colleague->id]])->assertOk()->assertJsonPath('data.member_ids.0', $colleague->id);

    $member = collect($this->getJson('/api/v1/settings/users')->json('data'))->firstWhere('id', $colleague->id);
    expect($member['modules'])->toBe(['procurement' => 'write'])->and($member['team_ids'])->toBe([$teamId]);
});

test('a temporary delegation lends the role only inside its window', function (): void {
    $admin = accessMember($this->tenant, 'admin');
    $holiday = accessMember($this->tenant, 'member');

    accessActAs($holiday, $this->tenant);
    $this->getJson('/api/v1/settings/users')->assertForbidden();

    accessActAs($admin, $this->tenant);
    $id = $this->postJson('/api/v1/settings/access/delegations', [
        'delegate_id' => $holiday->id,
        'starts_at' => now()->subHour()->toIso8601String(),
        'ends_at' => now()->addDay()->toIso8601String(),
        'reason' => 'Holiday',
    ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.role', 'admin')->json('data.id');

    accessActAs($holiday, $this->tenant);
    $this->getJson('/api/v1/settings/users')->assertOk();

    accessActAs($admin, $this->tenant);
    $this->deleteJson("/api/v1/settings/access/delegations/{$id}")->assertOk();

    accessActAs($holiday, $this->tenant);
    $this->getJson('/api/v1/settings/users')->assertForbidden();
});

test('a delegation must be in the future and between members', function (): void {
    $admin = accessMember($this->tenant, 'admin');
    accessActAs($admin, $this->tenant);

    $this->postJson('/api/v1/settings/access/delegations', [
        'delegate_id' => $admin->id,
        'starts_at' => now()->toIso8601String(),
        'ends_at' => now()->addDay()->toIso8601String(),
    ])->assertUnprocessable();
});

test('users are imported from CSV: accounts join, new addresses are invited, a dry run changes nothing', function (): void {
    Mail::fake();
    accessActAs(accessMember($this->tenant, 'owner'), $this->tenant);
    $existing = User::create(['name' => 'Known', 'email' => 'known@example.com', 'password' => Str::password(20)]);
    $this->postJson('/api/v1/settings/access/roles', ['name' => 'Buyer', 'base_role' => 'member'])->assertCreated();

    $csv = "email;name;role;team\nknown@example.com;Known;accountant;\nfresh@example.com;Fresh;Buyer;\nbroken;X;;\nother@example.com;O;nonexistent;\n";
    $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('users.csv', $csv);

    $dry = $this->post('/api/v1/settings/users/import', ['file' => $file(), 'dry_run' => '1'], ['Accept' => 'application/json'])->assertOk();
    expect($dry->json('data.created'))->toBe(1)->and($dry->json('data.invited'))->toBe(1)->and($dry->json('data.errors'))->toBe(2);
    expect($existing->fresh()->belongsToTenant($this->tenant->id))->toBeFalse();
    Mail::assertNothingQueued();

    $this->post('/api/v1/settings/users/import', ['file' => $file()], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('data.created', 1)->assertJsonPath('data.invited', 1);

    expect($existing->fresh()->belongsToTenant($this->tenant->id))->toBeTrue();
    Mail::assertQueued(TenantInvitationMail::class, 1);
});

test('every access change is traced in the audit history', function (): void {
    Mail::fake();
    $owner = accessMember($this->tenant, 'owner');
    accessActAs($owner, $this->tenant);
    $colleague = accessMember($this->tenant, 'member');

    $roleId = $this->postJson('/api/v1/settings/access/roles', ['name' => 'Auditable', 'base_role' => 'member'])->json('data.id');
    $this->putJson("/api/v1/settings/users/{$colleague->id}", ['custom_role_id' => $roleId])->assertOk();
    $this->postJson('/api/v1/settings/invitations', ['email' => 'trace@example.com'])->assertCreated();

    $events = collect($this->getJson('/api/v1/settings/access/audit')->assertOk()->json('data.data'))->pluck('event')->all();
    expect($events)->toContain('role.created', 'member.updated', 'invitation.created');

    $entry = collect($this->getJson('/api/v1/settings/access/audit?type=access.member')->json('data.data'))->first();
    expect($entry['actor']['id'])->toBe($owner->id)->and($entry['old_values'])->not->toBeNull()->and($entry['new_values']['custom_role_id'])->toBe($roleId);

    expect(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('event', 'role.created')->count())->toBe(1);
});
