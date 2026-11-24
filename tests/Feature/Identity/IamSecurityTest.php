<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\Platform\Identity\Infrastructure\Models\Role;
use Modules\Platform\Identity\Infrastructure\Models\RoleAssignment;

uses(RefreshDatabase::class);

function secUser(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => Str::password(20), 'email_verified_at' => now()]);
    $tenant->users()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

function secAct(User $user, Tenant $tenant): void
{
    app('auth')->forgetGuards();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    Artisan::call('permissions:sync');
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->owner = secUser($this->tenant, 'owner');
});

test('regression #230: a member is denied users, accounting, payroll and HR with permission_denied', function (string $uri): void {
    secAct(secUser($this->tenant, 'member'), $this->tenant);

    $this->getJson($uri)->assertForbidden();
})->with([
    '/api/v1/hr/employees',
    '/api/v1/payroll/absences',
    '/api/v1/accounting/ledger-entries',
    '/api/v1/general-ledger/accounts',
    '/api/v1/settings/roles',
]);

test('a refused permission key answers 403 with the stable permission_denied code', function (): void {
    secAct(secUser($this->tenant, 'member'), $this->tenant);

    $this->getJson('/api/v1/settings/roles')->assertForbidden()->assertJsonPath('code', 'permission_denied')
        ->assertJsonPath('type', 'urn:fatturafacile:problem:permission-denied');
});

test('owners and administrators need a second factor and cannot turn it off', function (): void {
    config(['iam.mandatory_mfa' => true]);
    $admin = secUser($this->tenant, 'admin');
    $member = secUser($this->tenant, 'member');

    secAct($admin, $this->tenant);
    $this->getJson('/api/v1/settings/roles')->assertForbidden()->assertJsonPath('code', 'mfa_required');

    secAct($member, $this->tenant);
    $this->getJson('/api/v1/profile/permissions')->assertOk();

});

test('an administrator with two factors is refused when turning them off', function (): void {
    $password = 'Bb2!'.Str::random(16);
    $admin = secUser($this->tenant, 'admin');
    $admin->forceFill(['password' => Hash::make($password)])->save();
    secAct($admin, $this->tenant);

    $secret = $this->postJson('/api/v1/auth/two-factor/enable', ['current_password' => $password])->assertOk()->json('data.secret');
    $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Totp::class)->codeAt($secret, time())])->assertOk();

    config(['iam.mandatory_mfa' => true]);
    $this->postJson('/api/v1/auth/two-factor/disable', ['current_password' => $password, 'code' => app(Totp::class)->codeAt($secret, time() + 30)])
        ->assertForbidden()->assertJsonPath('code', 'mfa_cannot_be_disabled');
});

test('masked and hidden fields never reach the response and are listed in meta.masked_fields', function (): void {
    $hr = secUser($this->tenant, 'hr');
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'base_annual_salary' => 83250.5, 'hourly_cost_rate' => 41.25]);

    secAct($this->owner, $this->tenant);
    $role = $this->postJson('/api/v1/settings/roles', ['name' => 'Salary blind', 'permissions' => ['hr.employees.view']])->json('data.id');
    $this->putJson("/api/v1/settings/roles/{$role}/field-policies", ['policies' => [
        ['resource' => 'hr.employee', 'field' => 'base_annual_salary', 'visibility' => 'masked'],
        ['resource' => 'hr.employee', 'field' => 'hourly_cost_rate', 'visibility' => 'hidden'],
    ]])->assertOk();

    secAct($hr, $this->tenant);
    $before = $this->getJson('/api/v1/hr/employees')->assertOk();
    expect(json_encode($before->json()))->toContain('83250.5');

    secAct($this->owner, $this->tenant);
    $this->putJson("/api/v1/settings/users/{$hr->id}/roles", ['role_ids' => [$role]])->assertOk();

    secAct($hr, $this->tenant);
    $response = $this->getJson('/api/v1/hr/employees')->assertOk();
    $raw = json_encode($response->json());

    expect($raw)->not->toContain('83250')->not->toContain('41.25')
        ->and($response->json('data.0'))->not->toHaveKey('hourly_cost_rate')
        ->and($response->json('data.0.base_annual_salary'))->toBe('•••')
        ->and($response->json('meta.masked_fields'))->toContain('hr.employee.base_annual_salary', 'hr.employee.hourly_cost_rate');

    // Another viewer, without the policy, still sees the real value.
    secAct($this->owner, $this->tenant);
    expect(json_encode($this->getJson('/api/v1/hr/employees')->json()))->toContain('83250.5');
});

test('revoking the sessions of a member ends their token at once', function (): void {
    config(['auth.token_mode.enabled' => true]);
    $member = secUser($this->tenant, 'member');
    $token = $member->createToken('phone')->plainTextToken;

    $callWithToken = function () use ($token) {
        app('auth')->forgetGuards();

        return $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/profile/permissions');
    };
    $callWithToken()->assertOk();

    secAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$member->id}/revoke-sessions")->assertOk()->assertJsonPath('data.tokens', 1);

    $callWithToken()->assertUnauthorized();
    expect(DB::table('enterprise_audit_ledger')->where('action', 'ACCESS.SECURITY.SESSIONS_REVOKED')->count())->toBe(1);
});

test('deactivating a user revokes their tokens', function (): void {
    config(['auth.token_mode.enabled' => true]);
    $member = secUser($this->tenant, 'member');
    $token = $member->createToken('phone')->plainTextToken;

    $member->forceFill(['is_active' => false])->save();

    app('auth')->forgetGuards();
    $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/profile/permissions')->assertUnauthorized();
});

test('only a user manager revokes sessions, never their own', function (): void {
    $member = secUser($this->tenant, 'member');
    secAct($member, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$this->owner->id}/revoke-sessions")->assertForbidden();

    $admin = secUser($this->tenant, 'admin');
    secAct($admin, $this->tenant);
    $this->postJson("/api/v1/settings/users/{$admin->id}/revoke-sessions")->assertStatus(409);
});

test('a company-scoped assignment is refused on another company with company_access_revoked', function (): void {
    $member = secUser($this->tenant, 'member');
    $role = Role::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', 'viewer')->firstOrFail();
    $companyA = (string) Str::uuid();
    RoleAssignment::create(['tenant_id' => $this->tenant->id, 'user_id' => $member->id, 'role_id' => $role->id, 'company_id' => $companyA]);

    secAct($member, $this->tenant);
    $this->withHeader('X-Company-Id', $companyA)->getJson('/api/v1/profile/permissions')->assertOk();
    $this->withHeader('X-Company-Id', (string) Str::uuid())->getJson('/api/v1/profile/permissions')
        ->assertForbidden()->assertJsonPath('code', 'company_access_revoked');
});

test('role and assignment changes land in the audit ledger and notify the member', function (): void {
    $member = secUser($this->tenant, 'member');
    secAct($this->owner, $this->tenant);
    $role = $this->postJson('/api/v1/settings/roles', ['name' => 'Reader', 'permissions' => ['crm.contacts.view']])->json('data.id');
    $this->putJson("/api/v1/settings/users/{$member->id}/roles", ['role_ids' => [$role]])->assertOk();

    $actions = DB::table('enterprise_audit_ledger')->where('tenant_id', $this->tenant->id)->pluck('action')->all();
    expect($actions)->toContain('ACCESS.SECURITY.ROLE_CREATED', 'ACCESS.SECURITY.ROLE_ASSIGNED');
    expect(DB::table('notifications')->where('notifiable_id', $member->id)->pluck('data')->implode(' '))->toContain('Your roles changed');
});

test('break-glass: only an owner with a second factor keeps the password login when the tenant is SSO-only', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'sso_only' => true]);
    $password = 'Cc3!'.Str::random(16);
    $owner = secUser($this->tenant, 'owner');
    $owner->forceFill(['password' => Hash::make($password), 'two_factor_secret' => Str::random(32), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['x']])->save();
    $plain = secUser($this->tenant, 'owner');
    $plain->forceFill(['password' => Hash::make($password)])->save();

    $this->postJson('/api/v1/auth/login', ['email' => $plain->email, 'password' => $password, 'token_mode' => true])
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:sso-required');

    $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => $password, 'token_mode' => true])
        ->assertOk()->assertJsonPath('data.two_factor_required', true);
    expect(DB::table('enterprise_audit_ledger')->where('action', 'PASSWORD_LOGIN_BREAK_GLASS')->exists())->toBeTrue();
});
