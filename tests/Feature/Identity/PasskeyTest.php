<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Platform\Identity\Domain\Events\PasskeyRegistered;
use Modules\Platform\Identity\Domain\Events\PasskeyRevoked;
use Modules\Platform\Identity\Infrastructure\Models\WebAuthnCredential;
use Tests\Support\SoftwarePasskey;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    config(['passkeys.rp_id' => 'app.test', 'passkeys.origins' => ['https://app.test'], 'auth.token_mode.enabled' => true]);

    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->user = User::create(['name' => 'Giulia', 'email' => Str::uuid().'@example.com', 'password' => Str::password(20), 'email_verified_at' => now()]);
    $this->tenant->users()->attach($this->user->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode([])]);
    $this->device = new SoftwarePasskey('app.test', 'https://app.test');
});

function registerPasskey(object $t, ?SoftwarePasskey $device = null, string $name = 'Laptop'): string
{
    $device ??= $t->device;
    Sanctum::actingAs($t->user);
    $t->withHeader('X-Tenant-Id', $t->tenant->id);

    $begin = $t->postJson('/api/v1/auth/passkeys/options')->assertOk();
    $options = $begin->json('data.options');

    expect($options['authenticatorSelection']['userVerification'])->toBe('required')
        ->and($options['rp']['id'])->toBe('app.test');

    return (string) $t->postJson('/api/v1/auth/passkeys', [
        'ceremony_id' => $begin->json('data.ceremony_id'),
        'name' => $name,
        'credential' => $device->create($options),
    ])->assertCreated()->json('data.id');
}

function passkeyLogin(object $t, SoftwarePasskey $device, ?int $counter = null): TestResponse
{
    app('auth')->forgetGuards();
    $begin = $t->postJson('/api/v1/auth/passkeys/login/options')->assertOk();

    return $t->postJson('/api/v1/auth/passkeys/login', [
        'ceremony_id' => $begin->json('data.ceremony_id'),
        'credential' => $device->assert($begin->json('data.options'), (string) $t->user->id, $counter),
        'token_mode' => true,
    ]);
}

test('a passkey is registered through the real attestation check and listed without its key', function (): void {
    Event::fake([PasskeyRegistered::class]);

    $id = registerPasskey($this);

    $this->getJson('/api/v1/auth/passkeys')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.name', 'Laptop')
        ->assertJsonMissingPath('data.0.public_key')->assertJsonMissingPath('data.0.credential_id');
    Event::assertDispatched(PasskeyRegistered::class);
    expect(WebAuthnCredential::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

test('registration is refused for a wrong origin, a reused ceremony and a missing user verification', function (): void {
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $begin = $this->postJson('/api/v1/auth/passkeys/options')->json('data');
    $evil = new SoftwarePasskey('app.test', 'https://evil.test');
    $this->postJson('/api/v1/auth/passkeys', ['ceremony_id' => $begin['ceremony_id'], 'name' => 'x', 'credential' => $evil->create($begin['options'])])->assertStatus(401);

    // The ceremony was consumed by the failed attempt: a second use is expired.
    $this->postJson('/api/v1/auth/passkeys', ['ceremony_id' => $begin['ceremony_id'], 'name' => 'x', 'credential' => $this->device->create($begin['options'])])->assertStatus(422);

    $begin = $this->postJson('/api/v1/auth/passkeys/options')->json('data');
    $this->postJson('/api/v1/auth/passkeys', ['ceremony_id' => $begin['ceremony_id'], 'name' => 'x', 'credential' => $this->device->create($begin['options'], userVerified: false)])->assertStatus(401);

    expect(WebAuthnCredential::query()->count())->toBe(0);
});

test('a passkey signs the user in, the counter grows and the access is a token', function (): void {
    registerPasskey($this);

    $response = passkeyLogin($this, $this->device)->assertOk();
    expect($response->json('data.token'))->toBeString()->and($response->json('data.user.id'))->toBe($this->user->id);
    expect(WebAuthnCredential::query()->first()->sign_count)->toBe(1);

    passkeyLogin($this, $this->device)->assertOk();
    expect(WebAuthnCredential::query()->first()->sign_count)->toBe(2);
});

test('a counter that does not grow revokes the credential as a clone', function (): void {
    Event::fake([PasskeyRevoked::class]);
    registerPasskey($this);
    passkeyLogin($this, $this->device, 5)->assertOk();

    passkeyLogin($this, $this->device, 3)->assertStatus(401)->assertJsonPath('type', 'urn:fatturafacile:passkey:authenticator_cloned');

    expect(WebAuthnCredential::query()->first()->revoked_at)->not->toBeNull();
    Event::assertDispatched(PasskeyRevoked::class);
    passkeyLogin($this, $this->device, 9)->assertStatus(401)->assertJsonPath('type', 'urn:fatturafacile:passkey:credential_revoked');
});

test('a synced passkey that always reports a zero counter keeps working', function (): void {
    $device = new SoftwarePasskey('app.test', 'https://app.test');
    registerPasskey($this, $device);

    passkeyLogin($this, $device, 0)->assertOk();
    passkeyLogin($this, $device, 0)->assertOk();
});

test('a revoked passkey cannot sign in and an unknown one is rejected', function (): void {
    $id = registerPasskey($this);
    $this->deleteJson("/api/v1/auth/passkeys/{$id}")->assertOk();

    passkeyLogin($this, $this->device)->assertStatus(401)->assertJsonPath('type', 'urn:fatturafacile:passkey:credential_revoked');
    passkeyLogin($this, new SoftwarePasskey('app.test', 'https://app.test'))->assertStatus(401)->assertJsonPath('type', 'urn:fatturafacile:passkey:credential_unknown');
});

test('a user revokes only their own passkey', function (): void {
    $id = registerPasskey($this);

    $other = User::create(['name' => 'Other', 'email' => Str::uuid().'@example.com', 'password' => Str::password(20), 'email_verified_at' => now()]);
    $this->tenant->users()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode([])]);
    Sanctum::actingAs($other);

    $this->deleteJson("/api/v1/auth/passkeys/{$id}")->assertNotFound();
    expect(WebAuthnCredential::query()->first()->revoked_at)->toBeNull();
});

test('a deactivated user cannot sign in with a passkey', function (): void {
    registerPasskey($this);
    $this->user->forceFill(['is_active' => false])->save();

    passkeyLogin($this, $this->device)->assertForbidden();
});

test('passkey events reach the audit ledger and notify the person', function (): void {
    $id = registerPasskey($this);
    $this->deleteJson("/api/v1/auth/passkeys/{$id}")->assertOk();

    $actions = DB::table('enterprise_audit_ledger')->where('tenant_id', $this->tenant->id)->pluck('action')->all();
    expect($actions)->toContain('ACCESS.SECURITY.PASSKEY_REGISTERED', 'ACCESS.SECURITY.PASSKEY_REVOKED');

    $titles = DB::table('notifications')->where('notifiable_id', $this->user->id)->pluck('data')->implode(' ');
    expect($titles)->toContain('Passkey added')->toContain('Passkey removed');
});
