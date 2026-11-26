<?php

declare(strict_types=1);

use App\Enums\Auth\AuthProblem;
use App\Models\TrustedDevice;
use App\Services\Auth\RegistrationAudit;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Modules\Platform\Identity\Infrastructure\Models\WebAuthnCredential;
use Tests\Support\MfaScenario;
use Tests\Support\SoftwarePasskey;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['passkeys.rp_id' => 'app.test', 'passkeys.origins' => ['https://app.test'], 'auth.token_mode.enabled' => true]);
    Mail::fake();

    $this->tenant = MfaScenario::tenant();
    $this->user = MfaScenario::member($this->tenant);
    $this->totp = MfaScenario::enableTotp($this->user, $this->tenant);
    $this->key = new SoftwarePasskey('app.test', 'https://app.test');
    $this->headers = ['X-Tenant-Id' => $this->tenant->id];
});

/**
 * Registers a security key for the user through the passkey API.
 */
function mfaRegisterKey(object $t, $user, SoftwarePasskey $key): void
{
    Sanctum::actingAs($user);
    $begin = test()->withHeaders($t->headers)->postJson('/api/v1/auth/passkeys/options')->assertOk();

    test()->withHeaders($t->headers)->postJson('/api/v1/auth/passkeys', [
        'ceremony_id' => $begin->json('data.ceremony_id'),
        'name' => 'YubiKey',
        'credential' => $key->create($begin->json('data.options')),
    ])->assertCreated();
}

/**
 * The options of the security key assertion of a challenge.
 *
 * @return array{ceremony_id: string, options: array<string, mixed>}
 */
function mfaKeyOptions(string $challengeToken): array
{
    return test()->postJson('/api/v1/auth/two-factor/challenge/webauthn/options', ['challenge_token' => $challengeToken])
        ->assertOk()
        ->json('data');
}

test('a security key answers the challenge of a user who has the app and the key', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);

    $login = MfaScenario::login($this->user)->assertOk();
    expect($login->json('data.methods'))->toBe(['totp', 'webauthn', 'recovery_code']);

    $token = $login->json('data.challenge_token');
    $begin = mfaKeyOptions($token);

    expect($begin['options']['rpId'])->toBe('app.test')
        ->and($begin['options']['userVerification'])->toBe('required')
        ->and($begin['options']['allowCredentials'])->toHaveCount(1);

    $answer = MfaScenario::answer($token, [
        'webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $this->key->assert($begin['options'], (string) $this->user->id)],
        'remember_device' => true,
    ])->assertOk();

    expect($answer->json('data.token'))->toBeString()
        ->and($answer->json('data.trusted_device_token'))->toBeString()
        ->and(TrustedDevice::query()->count())->toBe(1)
        ->and(WebAuthnCredential::query()->sole()->last_used_at)->not->toBeNull();

    $passed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_PASSED)->sole();
    expect($passed->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'webauthn'])
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_WEBAUTHN_USED)->count())->toBe(1);
});

test('the options are refused for an unknown challenge and for a user without a key', function (): void {
    $this->postJson('/api/v1/auth/two-factor/challenge/webauthn/options', ['challenge_token' => str_repeat('a', 64)])
        ->assertUnauthorized()
        ->assertJsonPath('type', AuthProblem::TwoFactorChallengeInvalid->value);

    $token = MfaScenario::challengeToken($this->user);

    $this->postJson('/api/v1/auth/two-factor/challenge/webauthn/options', ['challenge_token' => $token])
        ->assertStatus(409)
        ->assertJsonPath('type', AuthProblem::TwoFactorMethodUnavailable->value);
});

test('an assertion ceremony is single use', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);
    $token = MfaScenario::challengeToken($this->user);
    $begin = mfaKeyOptions($token);
    $credential = $this->key->assert($begin['options'], (string) $this->user->id, 7);
    $answer = ['webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $credential]];

    MfaScenario::answer($token, $answer)->assertOk();

    $second = MfaScenario::challengeToken($this->user);
    MfaScenario::answer($second, $answer)->assertUnauthorized();

    $failed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->sole();
    expect($failed->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'webauthn', 'reason' => 'ceremony_expired']);
});

test('a key of another user never answers the challenge', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);

    $other = MfaScenario::member($this->tenant);
    $otherKey = new SoftwarePasskey('app.test', 'https://app.test');
    mfaRegisterKey($this, $other, $otherKey);

    $token = MfaScenario::challengeToken($this->user);
    $begin = mfaKeyOptions($token);

    MfaScenario::answer($token, [
        'webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $otherKey->assert($begin['options'], (string) $other->id)],
    ])->assertUnauthorized()->assertJsonPath('type', AuthProblem::TwoFactorCodeInvalid->value);

    $failed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->sole();
    expect($failed->payload_after)->toMatchArray(['mfa_method' => 'webauthn', 'reason' => 'credential_unknown']);
});

test('a ceremony of one challenge cannot be used on the challenge of another user', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);

    $other = MfaScenario::member($this->tenant);
    MfaScenario::enableTotp($other, $this->tenant);
    $otherKey = new SoftwarePasskey('app.test', 'https://app.test');
    mfaRegisterKey($this, $other, $otherKey);

    $begin = mfaKeyOptions(MfaScenario::challengeToken($this->user));
    $otherToken = MfaScenario::challengeToken($other);

    MfaScenario::answer($otherToken, [
        'webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $otherKey->assert($begin['options'], (string) $other->id)],
    ])->assertUnauthorized();
});

test('an assertion from a wrong origin is refused and counts as a failed attempt', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);
    $token = MfaScenario::challengeToken($this->user);
    $begin = mfaKeyOptions($token);

    $evil = new SoftwarePasskey('app.test', 'https://evil.test');
    $evil->credentialId = $this->key->credentialId;

    MfaScenario::answer($token, [
        'webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $evil->assert($begin['options'], (string) $this->user->id)],
    ])->assertUnauthorized();

    expect(WebAuthnCredential::query()->sole()->revoked_at)->toBeNull()
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->count())->toBe(1);
});

test('a counter that does not grow revokes the key as a clone and the challenge fails', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);

    $first = MfaScenario::challengeToken($this->user);
    $begin = mfaKeyOptions($first);
    MfaScenario::answer($first, ['webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $this->key->assert($begin['options'], (string) $this->user->id, 5)]])->assertOk();

    $second = MfaScenario::challengeToken($this->user);
    $begin = mfaKeyOptions($second);
    MfaScenario::answer($second, ['webauthn' => ['ceremony_id' => $begin['ceremony_id'], 'credential' => $this->key->assert($begin['options'], (string) $this->user->id, 3)]])->assertUnauthorized();

    expect(WebAuthnCredential::query()->sole()->revoked_at)->not->toBeNull();

    $failed = AuditLedgerEntry::query()->where('action', RegistrationAudit::TWO_FACTOR_CHALLENGE_FAILED)->sole();
    expect($failed->payload_after)->toMatchArray(['mfa_method' => 'webauthn', 'reason' => 'authenticator_cloned']);

    // A revoked key leaves the method list.
    $login = MfaScenario::login($this->user)->assertOk();
    expect($login->json('data.methods'))->toBe(['totp', 'recovery_code']);
});

test('a user with only passkeys and no enrolled factor is not challenged after the password', function (): void {
    $solo = MfaScenario::member($this->tenant);
    $soloKey = new SoftwarePasskey('app.test', 'https://app.test');
    mfaRegisterKey($this, $solo, $soloKey);

    MfaScenario::login($solo)->assertOk()->assertJsonPath('data.two_factor_required', null)->assertJsonPath('data.user.two_factor_methods', []);
});

test('the status lists the methods of the user including the security key', function (): void {
    mfaRegisterKey($this, $this->user, $this->key);

    $this->withHeaders($this->headers)->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('data.methods', ['totp', 'webauthn', 'recovery_code'])
        ->assertJsonPath('data.webauthn_enabled', true)
        ->assertJsonPath('data.totp_enabled', true)
        ->assertJsonPath('data.email_enabled', false);
});
