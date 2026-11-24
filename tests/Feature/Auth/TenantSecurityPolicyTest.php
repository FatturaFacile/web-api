<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantSecurityPolicy;
use App\Models\User;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// These tests exercise the optional bearer-token mode, which is off by default: they turn it on and ask for it.
beforeEach(fn () => config(['auth.token_mode.enabled' => true]));

beforeEach(function (): void {
    $this->password = Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->tenant = Tenant::factory()->create();
    $this->admin = policyMember($this->tenant, 'admin', 'laura.verdi@example.com', $this->password);
    $this->member = policyMember($this->tenant, 'member', 'marco.neri@example.com', $this->password);
});

function policyMember(Tenant $tenant, string $role, string $email, string $password): User
{
    $user = User::create([
        'name' => Str::before($email, '@'),
        'email' => $email,
        'password' => Hash::make($password),
        'locale' => 'it',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function policyStrongPassword(): string
{
    return Str::password(16).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
}

test('an administrator reads and updates the policy, and the change is audited', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/policy')
        ->assertOk()->assertJsonPath('data.lockout.max_attempts', 0)->assertJsonPath('data.two_factor.require_for', 'none');

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', [
        'password' => ['min_length' => 14, 'history_count' => 3, 'max_age_days' => 90],
        'lockout' => ['max_attempts' => 5, 'minutes' => 30],
        'session' => ['idle_timeout_minutes' => 15, 'max_concurrent_sessions' => 2],
    ])->assertOk()
        ->assertJsonPath('data.password.min_length', 14)
        ->assertJsonPath('data.lockout.minutes', 30)
        ->assertJsonPath('data.session.max_concurrent_sessions', 2);

    expect(AuditLedgerEntry::query()->where('action', 'SECURITY_POLICY_CHANGED')->where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

test('the policy values are validated', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', [
        'password' => ['min_length' => 3, 'history_count' => 99],
        'lockout' => ['max_attempts' => 1],
        'session' => ['idle_timeout_minutes' => 1],
        'two_factor' => ['require_for' => 'roles'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['password.min_length', 'password.history_count', 'lockout.max_attempts', 'session.idle_timeout_minutes', 'two_factor.roles']);
});

test('ordinary members cannot read or change the policy', function (): void {
    Sanctum::actingAs($this->member);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/policy')->assertForbidden();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['sso_only' => false])->assertForbidden();
});

test('a policy of one tenant does not apply to another', function (): void {
    $other = Tenant::factory()->create();
    $otherAdmin = policyMember($other, 'admin', 'olivia.admin@example.com', $this->password);
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'password_min_length' => 30]);

    Sanctum::actingAs($otherAdmin);
    $this->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/settings/security/policy')->assertOk()->assertJsonPath('data.password.min_length', 10);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/policy')->assertForbidden();
});

test('a tenant cannot weaken the platform password rules', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'password_min_length' => 8, 'password_require_symbols' => false]);

    Sanctum::actingAs($this->admin);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/security/policy')
        ->assertJsonPath('data.password.min_length', 10)->assertJsonPath('data.password.require_symbols', true);
});

test('a password change that breaks the tenant length rule or reuses a recent password is refused', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'password_min_length' => 24, 'password_history_count' => 2]);
    Sanctum::actingAs($this->member);

    $short = policyStrongPassword();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/auth/password', [
        'current_password' => $this->password, 'password' => $short, 'password_confirmation' => $short,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $long = Str::password(30).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/auth/password', [
        'current_password' => $this->password, 'password' => $long, 'password_confirmation' => $long,
    ])->assertOk();

    $another = Str::password(30).chr(random_int(65, 90)).chr(random_int(97, 122)).random_int(0, 9);
    expect(DB::table('password_histories')->where('user_id', $this->member->id)->count())->toBe(1);

    // Back to the very first password is refused by the history, which holds the one just left.
    Sanctum::actingAs($this->member->fresh());
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/auth/password', [
        'current_password' => $long, 'password' => $long.'x', 'password_confirmation' => $long.'x',
    ])->assertOk();
    $this->member->refresh();
    Sanctum::actingAs($this->member);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/auth/password', [
        'current_password' => $long.'x', 'password' => $long, 'password_confirmation' => $long,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
    expect($another)->not->toBe($long);
});

test('repeated wrong passwords lock the account; a wrong password still looks the same, the right one learns it is locked', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'lockout_max_attempts' => 3, 'lockout_minutes' => 10]);

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password.'x', 'token_mode' => true])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password.'x', 'token_mode' => true])
        ->assertUnauthorized()->assertJsonPath('title', 'Invalid Credentials');
    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])
        ->assertStatus(423)->assertJsonPath('type', 'urn:fatturafacile:problem:account-locked');

    expect(AuditLedgerEntry::query()->where('action', 'LOGIN_LOCKED')->count())->toBe(1);

    $this->travel(11)->minutes();
    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk();
    expect($this->member->fresh()->failed_login_attempts)->toBe(0);
});

test('a successful login resets the failure counter', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'lockout_max_attempts' => 3]);

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password.'x', 'token_mode' => true])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk();

    expect($this->member->fresh()->failed_login_attempts)->toBe(0);
});

test('without a lockout policy nobody is locked', function (): void {
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password.'x', 'token_mode' => true])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk();
});

test('a password older than the maximum age cannot sign in until it is reset', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'password_max_age_days' => 30]);
    $this->member->forceFill(['password_changed_at' => now()->subDays(31)])->save();

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:password-expired');

    $this->member->forceFill(['password_changed_at' => now()->subDays(5)])->save();
    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk();
});

test('an access idle for longer than the limit is revoked', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'session_idle_timeout_minutes' => 15]);
    $token = $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk()->json('data.token');

    $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();

    $this->travel(16)->minutes();
    $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')
        ->assertUnauthorized()->assertJsonPath('type', 'urn:fatturafacile:problem:session-expired');
    expect(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(AuditLedgerEntry::query()->where('action', 'SESSION_IDLE_EXPIRED')->count())->toBe(1);
});

test('activity keeps an access alive', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'session_idle_timeout_minutes' => 15]);
    $token = $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk()->json('data.token');

    for ($step = 0; $step < 3; $step++) {
        $this->travel(10)->minutes();
        $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();
    }
});

test('opening an access beyond the concurrent limit revokes the least recently used one', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'max_concurrent_sessions' => 2]);

    $first = $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk()->json('data.token');
    $this->travel(1)->minutes();
    $second = $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk()->json('data.token');
    $this->travel(1)->minutes();
    $third = $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertOk()->json('data.token');

    expect(DB::table('personal_access_tokens')->count())->toBe(2);
    $this->app['auth']->forgetGuards();
    $this->withToken($first)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($second)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($third)->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();
    expect(AuditLedgerEntry::query()->where('action', 'SESSION_LIMIT_REVOKED')->count())->toBe(1);
});

test('two factors can be required of some roles only', function (): void {
    TenantSecurityPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'two_factor_roles' => ['accountant']]);
    $accountant = policyMember($this->tenant, 'accountant', 'chiara.conti@example.com', $this->password);

    Sanctum::actingAs($accountant);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/tenant')
        ->assertForbidden()->assertJsonPath('type', 'urn:fatturafacile:problem:two-factor-setup-required');
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/auth/profile')->assertOk();

    Sanctum::actingAs($this->member);
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->getJson('/api/v1/settings/tenant')->assertOk();

    $login = $this->postJson('/api/v1/auth/login', ['email' => $accountant->email, 'password' => $this->password, 'token_mode' => true])->assertOk();
    expect($login->json('data.two_factor_setup_required'))->toBeTrue();
});

test('requiring two factors for roles needs the administrator to have them, and the modes are reported', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['two_factor' => ['require_for' => 'roles', 'roles' => ['admin']]])
        ->assertStatus(409)->assertJsonPath('type', 'urn:fatturafacile:problem:two-factor-setup-required');
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['two_factor' => ['require_for' => 'all']])->assertStatus(409);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['two_factor' => ['require_for' => 'roles', 'roles' => ['accountant']]])
        ->assertOk()->assertJsonPath('data.two_factor.require_for', 'roles')->assertJsonPath('data.two_factor.roles.0', 'accountant');
});

test('SSO-only cannot be turned on without a ready provider and an own SSO identity', function (): void {
    Sanctum::actingAs($this->admin);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->putJson('/api/v1/settings/security/policy', ['sso_only' => true])->assertStatus(409);
    expect(TenantSecurityPolicy::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->exists())->toBeFalse();
});

test('a suspended membership is no membership', function (): void {
    DB::table('tenant_user')->where('user_id', $this->member->id)->update(['suspended_at' => now()]);

    $this->postJson('/api/v1/auth/login', ['email' => $this->member->email, 'password' => $this->password, 'token_mode' => true])->assertForbidden();
    expect($this->member->fresh()->belongsToTenant($this->tenant->id))->toBeFalse();
});
