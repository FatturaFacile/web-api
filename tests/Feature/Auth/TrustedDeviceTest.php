<?php

declare(strict_types=1);

use App\Models\TrustedDevice;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TwoFactorAuthentication;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MfaScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['auth.token_mode.enabled' => true, 'auth.two_factor.trusted_device_days' => 30]);
    Mail::fake();

    $this->tenant = MfaScenario::tenant();
    $this->user = MfaScenario::member($this->tenant);
    $this->totp = MfaScenario::enableTotp($this->user, $this->tenant);
    $this->headers = ['X-Tenant-Id' => $this->tenant->id];
});

/**
 * Logs in, answers the challenge with the next app code and asks to remember the device; returns the response data.
 *
 * @return array<string, mixed>
 */
function rememberedDevice(object $t): array
{
    $challenge = MfaScenario::challengeToken($t->user);

    return MfaScenario::answer($challenge, ['code' => MfaScenario::totpCode($t->totp['secret']), 'remember_device' => true])
        ->assertOk()
        ->json('data');
}

test('remembering the device at the challenge returns a token once and stores only its hash', function (): void {
    $data = rememberedDevice($this);

    expect($data['trusted_device_token'])->toBeString()->toHaveLength(64)
        ->and($data['trusted_device']['id'])->toBeString()
        ->and($data['trusted_device']['expires_at'])->toBeString();

    $device = TrustedDevice::query()->sole();

    expect($device->user_id)->toBe($this->user->id)
        ->and($device->token_hash)->toBe(hash('sha256', $data['trusted_device_token']))
        ->and($device->token_hash)->not->toBe($data['trusted_device_token'])
        ->and($device->expires_at->isBetween(now()->addDays(29), now()->addDays(31)))->toBeTrue();

    $created = AuditLedgerEntry::query()->where('action', RegistrationAudit::TRUSTED_DEVICE_CREATED)->sole();
    expect($created->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => 'totp'])
        ->and(json_encode($created->payload_after))->not->toContain($data['trusted_device_token']);
});

test('a challenge without the wish to remember the device issues no token', function (): void {
    $challenge = MfaScenario::challengeToken($this->user);

    $response = MfaScenario::answer($challenge, ['code' => MfaScenario::totpCode($this->totp['secret'])])->assertOk();

    expect($response->json('data.trusted_device_token'))->toBeNull()
        ->and(TrustedDevice::query()->count())->toBe(0);
});

test('the feature is off when the number of days is zero', function (): void {
    config(['auth.two_factor.trusted_device_days' => 0]);
    $challenge = MfaScenario::challengeToken($this->user);

    $response = MfaScenario::answer($challenge, ['code' => MfaScenario::totpCode($this->totp['secret']), 'remember_device' => true])->assertOk();

    expect($response->json('data.trusted_device_token'))->toBeNull()
        ->and(TrustedDevice::query()->count())->toBe(0);
});

test('a valid trusted device token skips the second factor and the login is audited with the method', function (): void {
    $token = rememberedDevice($this)['trusted_device_token'];
    $before = TrustedDevice::query()->sole()->last_used_at;

    $this->travel(5)->minutes();
    $login = MfaScenario::login($this->user, ['trusted_device_token' => $token])->assertOk();

    expect($login->json('data.two_factor_required'))->toBeNull()
        ->and($login->json('data.token'))->toBeString()
        ->and($login->json('data.user.two_factor_enabled'))->toBeTrue();

    $used = AuditLedgerEntry::query()->where('action', RegistrationAudit::TRUSTED_DEVICE_USED)->sole();
    expect($used->payload_after)->toMatchArray(['auth_method' => 'password', 'mfa_method' => TwoFactorAuthentication::METHOD_TRUSTED_DEVICE])
        ->and(TrustedDevice::query()->sole()->last_used_at->greaterThan($before))->toBeTrue();
});

test('an unknown or malformed token does not skip the challenge', function (): void {
    rememberedDevice($this);

    MfaScenario::login($this->user, ['trusted_device_token' => str_repeat('a', 64)])->assertOk()->assertJsonPath('data.two_factor_required', true);
    MfaScenario::login($this->user, ['trusted_device_token' => 'short'])->assertUnprocessable();
});

test('an expired trusted device no longer skips the challenge', function (): void {
    $token = rememberedDevice($this)['trusted_device_token'];

    $this->travel(31)->days();

    MfaScenario::login($this->user, ['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);
    expect((new TrustedDevice)->prunable()->count())->toBe(1);
});

test('a trusted device of one user never skips the challenge of another user', function (): void {
    $token = rememberedDevice($this)['trusted_device_token'];

    $other = MfaScenario::member($this->tenant);
    MfaScenario::enableTotp($other, $this->tenant);

    MfaScenario::login($other, ['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);

    $otherTenant = MfaScenario::tenant('Altra Azienda');
    $stranger = MfaScenario::member($otherTenant);
    MfaScenario::enableTotp($stranger, $otherTenant);

    MfaScenario::login($stranger, ['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);
});

test('a user lists only their own devices, without any token', function (): void {
    $token = rememberedDevice($this)['trusted_device_token'];

    $other = MfaScenario::member($this->tenant);
    MfaScenario::enableTotp($other, $this->tenant);

    Sanctum::actingAs($this->user);
    $list = $this->withHeaders($this->headers)->getJson('/api/v1/auth/two-factor/trusted-devices')->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0'))->toHaveKeys(['id', 'label', 'ip_address', 'last_used_at', 'created_at', 'expires_at'])
        ->and($list->json('meta.days'))->toBe(30)
        ->and($list->getContent())->not->toContain($token)
        ->and($list->getContent())->not->toContain('token_hash');

    Sanctum::actingAs($other);
    $this->withHeaders($this->headers)->getJson('/api/v1/auth/two-factor/trusted-devices')->assertOk()->assertJsonCount(0, 'data');
});

test('a device is revoked by its owner only, and a revoked token skips nothing', function (): void {
    $token = rememberedDevice($this)['trusted_device_token'];
    $id = TrustedDevice::query()->sole()->id;

    $other = MfaScenario::member($this->tenant);
    Sanctum::actingAs($other);
    $this->withHeaders($this->headers)->deleteJson("/api/v1/auth/two-factor/trusted-devices/{$id}")->assertNotFound();
    expect(TrustedDevice::query()->count())->toBe(1);

    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->deleteJson("/api/v1/auth/two-factor/trusted-devices/{$id}")->assertOk();
    $this->withHeaders($this->headers)->deleteJson("/api/v1/auth/two-factor/trusted-devices/{$id}")->assertNotFound();

    expect(TrustedDevice::query()->count())->toBe(0)
        ->and(AuditLedgerEntry::query()->where('action', RegistrationAudit::TRUSTED_DEVICE_REVOKED)->count())->toBe(1);

    MfaScenario::login($this->user, ['trusted_device_token' => $token])->assertOk()->assertJsonPath('data.two_factor_required', true);
});

test('every device of the user is revoked at once', function (): void {
    rememberedDevice($this);
    $this->travel(2)->minutes();
    $challenge = MfaScenario::challengeToken($this->user);
    MfaScenario::answer($challenge, ['recovery_code' => $this->totp['recovery_codes'][0], 'remember_device' => true])->assertOk();
    expect(TrustedDevice::query()->count())->toBe(2);

    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->deleteJson('/api/v1/auth/two-factor/trusted-devices')
        ->assertOk()
        ->assertJsonPath('data.revoked', 2);

    expect(TrustedDevice::query()->count())->toBe(0);
});

test('changing the password deletes the trusted devices', function (): void {
    rememberedDevice($this);

    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->putJson('/api/v1/auth/password', [
        'current_password' => MfaScenario::PASSWORD,
        'password' => 'Zz9!Another-Long-Pass-456',
        'password_confirmation' => 'Zz9!Another-Long-Pass-456',
        'recovery_code' => $this->totp['recovery_codes'][0],
    ])->assertOk();

    expect(TrustedDevice::query()->count())->toBe(0);
});

test('revoking all the other sessions deletes the trusted devices', function (): void {
    rememberedDevice($this);

    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/sessions/revoke-others', ['current_password' => MfaScenario::PASSWORD])->assertOk();

    expect(TrustedDevice::query()->count())->toBe(0);
});

test('turning the authenticator app off or resetting the factors deletes the trusted devices', function (): void {
    rememberedDevice($this);

    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->postJson('/api/v1/auth/two-factor/disable', [
        'current_password' => MfaScenario::PASSWORD,
        'recovery_code' => $this->totp['recovery_codes'][0],
    ])->assertOk();

    expect(TrustedDevice::query()->count())->toBe(0);

    $again = MfaScenario::member($this->tenant);
    MfaScenario::enableTotp($again, $this->tenant);
    TrustedDevice::query()->create(['user_id' => $again->id, 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay()]);

    app(TwoFactorAuthentication::class)->reset($again);

    expect(TrustedDevice::query()->count())->toBe(0);
});

test('deactivating the user deletes the trusted devices', function (): void {
    rememberedDevice($this);

    $this->user->forceFill(['is_active' => false])->save();

    expect(TrustedDevice::query()->count())->toBe(0);
});
