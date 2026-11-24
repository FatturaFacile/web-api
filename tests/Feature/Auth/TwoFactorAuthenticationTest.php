<?php

declare(strict_types=1);

use App\Enums\Auth\AuthProblem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// These tests exercise the optional bearer-token mode, which is off by default: they turn it on and ask for it.
beforeEach(fn () => config(['auth.token_mode.enabled' => true]));

beforeEach(function (): void {
    $this->password = 'Bb2!'.Str::random(16);
    $this->tenant = Tenant::create([
        'name' => 'Officina Verdi',
        'legal_name' => 'Officina Verdi SRL',
        'fiscal_code' => '12345678901',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $this->admin = twoFactorMember($this->tenant, 'admin', 'laura.verdi@example.com', $this->password);
});

function twoFactorMember(Tenant $tenant, string $role, string $email, string $password): User
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

/**
 * Turns two factors on through the API and returns the secret and the recovery codes.
 *
 * @return array{secret: string, recovery_codes: list<string>}
 */
function enableTwoFactor(User $user, Tenant $tenant, string $password): array
{
    Sanctum::actingAs($user);
    $secret = test()->withHeader('X-Tenant-Id', $tenant->id)
        ->postJson('/api/v1/auth/two-factor/enable', ['current_password' => $password])
        ->assertOk()
        ->json('data.secret');

    $codes = test()->withHeader('X-Tenant-Id', $tenant->id)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Totp::class)->codeAt($secret, time())])
        ->assertOk()
        ->json('data.recovery_codes');

    return ['secret' => $secret, 'recovery_codes' => $codes];
}

function loginChallenge(string $email, string $password): string
{
    $response = test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'token_mode' => true])->assertOk();

    expect($response->json('data.two_factor_required'))->toBeTrue()
        ->and($response->json('data.token'))->toBeNull();

    return (string) $response->json('data.challenge_token');
}

test('the TOTP implementation matches the RFC 6238 SHA-1 test vectors', function (): void {
    $totp = app(Totp::class);
    $secret = Totp::base32Encode('12345678901234567890');

    expect($secret)->toBe('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ')
        ->and($totp->codeAt($secret, 59, 8))->toBe('94287082')
        ->and($totp->codeAt($secret, 1111111109, 8))->toBe('07081804')
        ->and($totp->codeAt($secret, 1234567890, 8))->toBe('89005924')
        ->and($totp->codeAt($secret, 2000000000, 8))->toBe('69279037')
        ->and(Totp::base32Decode($secret))->toBe('12345678901234567890');
});

test('a code is accepted within one step of drift and never twice', function (): void {
    $totp = app(Totp::class);
    $secret = $totp->generateSecret();
    $now = 1_800_000_000;

    $step = $totp->verify($secret, $totp->codeAt($secret, $now - 30), null, $now);

    expect($step)->toBe(intdiv($now, 30) - 1)
        ->and($totp->verify($secret, $totp->codeAt($secret, $now - 30), $step, $now))->toBeNull()
        ->and($totp->verify($secret, $totp->codeAt($secret, $now - 90), null, $now))->toBeNull()
        ->and($totp->verify($secret, 'abcdef', null, $now))->toBeNull();
});

test('setup needs the password and a first valid code, then returns recovery codes once', function (): void {
    Sanctum::actingAs($this->admin);
    $headers = ['X-Tenant-Id' => $this->tenant->id];

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/enable', ['current_password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');

    $setup = $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/enable', ['current_password' => $this->password])
        ->assertOk()
        ->json('data');

    expect($setup['otpauth_uri'])->toStartWith('otpauth://totp/')
        ->toContain('secret='.$setup['secret']);

    $this->withHeaders($headers)->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.pending_setup', true);

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
        ->assertUnprocessable()
        ->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);

    $codes = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => app(Totp::class)->codeAt($setup['secret'], time())])
        ->assertOk()
        ->json('data.recovery_codes');

    expect($codes)->toHaveCount(8);

    $this->withHeaders($headers)->getJson('/api/v1/auth/two-factor')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.remaining_recovery_codes', 8);

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/enable', ['current_password' => $this->password])
        ->assertStatus(409);
});

test('the secret and the recovery codes are encrypted at rest and never serialised', function (): void {
    $setup = enableTwoFactor($this->admin, $this->tenant, $this->password);
    $row = DB::table('users')->where('id', $this->admin->id)->first();

    expect($row->two_factor_secret)->not->toContain($setup['secret'])
        ->and((string) $row->two_factor_recovery_codes)->not->toContain($setup['recovery_codes'][0])
        ->and($this->admin->fresh()->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);
});

test('a login with two factors gets a challenge, and the token only with a valid code used once', function (): void {
    $setup = enableTwoFactor($this->admin, $this->tenant, $this->password);
    $this->app['auth']->forgetGuards();

    $challenge = loginChallenge('laura.verdi@example.com', $this->password);
    $nextCode = app(Totp::class)->codeAt($setup['secret'], time() + 30);

    $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => '000000'])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);

    $token = $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $nextCode])
        ->assertOk()
        ->assertJsonPath('data.user.two_factor_enabled', true)
        ->assertJsonPath('data.active_tenant.id', $this->tenant->id)
        ->json('data.token');

    expect($token)->toBeString()->not->toBeEmpty();

    // The challenge is spent, and the same code does not open a second login.
    $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => $nextCode])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorChallengeInvalid->value);

    $second = loginChallenge('laura.verdi@example.com', $this->password);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $second, 'code' => $nextCode])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);
});

test('a recovery code completes a login once', function (): void {
    $setup = enableTwoFactor($this->admin, $this->tenant, $this->password);
    $this->app['auth']->forgetGuards();
    $recoveryCode = strtoupper($setup['recovery_codes'][3]);

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => loginChallenge('laura.verdi@example.com', $this->password),
        'recovery_code' => $recoveryCode,
    ])->assertOk();

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => loginChallenge('laura.verdi@example.com', $this->password),
        'recovery_code' => $recoveryCode,
    ])->assertUnauthorized();

    expect(count($this->admin->fresh()->two_factor_recovery_codes))->toBe(7);
});

test('a challenge is dropped after the maximum number of wrong codes', function (): void {
    config(['auth.two_factor.max_challenge_attempts' => 3]);
    $setup = enableTwoFactor($this->admin, $this->tenant, $this->password);
    $this->app['auth']->forgetGuards();
    $challenge = loginChallenge('laura.verdi@example.com', $this->password);

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge_token' => $challenge, 'code' => '000000'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challenge,
        'code' => app(Totp::class)->codeAt($setup['secret'], time() + 30),
    ])->assertUnauthorized()->assertJsonPath('type', AuthProblem::TwoFactorChallengeInvalid->value);
});

test('disabling needs the password and a second factor', function (): void {
    $setup = enableTwoFactor($this->admin, $this->tenant, $this->password);
    $headers = ['X-Tenant-Id' => $this->tenant->id];

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/disable', ['current_password' => $this->password, 'code' => '000000'])
        ->assertUnprocessable();

    $this->withHeaders($headers)->postJson('/api/v1/auth/two-factor/disable', [
        'current_password' => $this->password,
        'recovery_code' => $setup['recovery_codes'][0],
    ])->assertOk()->assertJsonPath('data.enabled', false);

    expect($this->admin->fresh()->two_factor_secret)->toBeNull();
});

test('a tenant that requires two factors confines members without them to the authentication routes', function (): void {
    $member = twoFactorMember($this->tenant, 'accountant', 'paolo.neri@example.com', $this->password);
    $headers = ['X-Tenant-Id' => $this->tenant->id];

    Sanctum::actingAs($this->admin);
    $this->withHeaders($headers)->putJson('/api/v1/settings/security/two-factor', ['require_two_factor' => true])
        ->assertStatus(409)
        ->assertJsonPath('type', AuthProblem::TwoFactorSetupRequired->value);

    enableTwoFactor($this->admin, $this->tenant, $this->password);
    $this->withHeaders($headers)->putJson('/api/v1/settings/security/two-factor', ['require_two_factor' => true])
        ->assertOk()
        ->assertJsonPath('data.require_two_factor', true);

    Sanctum::actingAs($member);
    $this->withHeaders($headers)->getJson('/api/v1/settings/tenant')
        ->assertForbidden()
        ->assertJsonPath('type', AuthProblem::TwoFactorSetupRequired->value);
    $this->withHeaders($headers)->getJson('/api/v1/auth/profile')->assertOk();
    $this->withHeaders($headers)->getJson('/api/v1/auth/two-factor')->assertOk()->assertJsonPath('data.required_by_tenant', true);

    // The member logs in, is told to set up two factors, and after the setup reaches the other routes.
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['email' => 'paolo.neri@example.com', 'password' => $this->password, 'token_mode' => true])
        ->assertOk()
        ->assertJsonPath('data.two_factor_setup_required', true);

    enableTwoFactor($member, $this->tenant, $this->password);
    $this->withHeaders($headers)->getJson('/api/v1/settings/tenant')->assertOk();
});

test('only administrators manage the tenant policy', function (): void {
    $member = twoFactorMember($this->tenant, 'accountant', 'paolo.neri@example.com', $this->password);
    Sanctum::actingAs($member);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->putJson('/api/v1/settings/security/two-factor', ['require_two_factor' => false])
        ->assertForbidden();
});

test('an administrator resets the two factors of a member of this tenant alone', function (): void {
    $member = twoFactorMember($this->tenant, 'accountant', 'paolo.neri@example.com', $this->password);
    enableTwoFactor($member, $this->tenant, $this->password);
    $member->createToken('phone');

    $otherTenant = Tenant::create(['name' => 'Altra', 'legal_name' => 'Altra SRL', 'fiscal_code' => '10987654321', 'country' => 'IT', 'default_currency' => 'EUR']);
    $shared = twoFactorMember($this->tenant, 'sales', 'anna.blu@example.com', $this->password);
    $shared->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    $outsider = twoFactorMember($otherTenant, 'owner', 'marco.gialli@example.com', $this->password);

    Sanctum::actingAs($this->admin);
    $headers = ['X-Tenant-Id' => $this->tenant->id];

    $this->withHeaders($headers)->postJson("/api/v1/settings/users/{$member->id}/two-factor/reset")
        ->assertOk()
        ->assertJsonPath('data.two_factor_enabled', false);

    expect($member->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and($member->tokens()->count())->toBe(0);

    $this->withHeaders($headers)->postJson("/api/v1/settings/users/{$shared->id}/two-factor/reset")->assertStatus(409);
    $this->withHeaders($headers)->postJson("/api/v1/settings/users/{$outsider->id}/two-factor/reset")->assertNotFound();
    $this->withHeaders($headers)->postJson("/api/v1/settings/users/{$this->admin->id}/two-factor/reset")->assertStatus(409);
});

test('a login without two factors still returns the token directly', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'laura.verdi@example.com', 'password' => $this->password, 'token_mode' => true])
        ->assertOk()
        ->assertJsonPath('data.user.two_factor_enabled', false)
        ->assertJsonPath('data.two_factor_setup_required', false)
        ->assertJsonStructure(['data' => ['token']]);
});
