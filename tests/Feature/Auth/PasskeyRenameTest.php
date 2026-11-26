<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Platform\Identity\Domain\Events\PasskeyRenamed;
use Modules\Platform\Identity\Infrastructure\Models\WebAuthnCredential;
use Tests\Support\MfaScenario;
use Tests\Support\SoftwarePasskey;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['passkeys.rp_id' => 'app.test', 'passkeys.origins' => ['https://app.test']]);

    $this->tenant = MfaScenario::tenant();
    $this->user = MfaScenario::member($this->tenant);
    $this->headers = ['X-Tenant-Id' => $this->tenant->id];
});

function renameTestPasskey(object $t, $user, string $name = 'Laptop'): string
{
    Sanctum::actingAs($user);
    $begin = test()->withHeaders($t->headers)->postJson('/api/v1/auth/passkeys/options')->assertOk();

    return (string) test()->withHeaders($t->headers)->postJson('/api/v1/auth/passkeys', [
        'ceremony_id' => $begin->json('data.ceremony_id'),
        'name' => $name,
        'credential' => (new SoftwarePasskey('app.test', 'https://app.test'))->create($begin->json('data.options')),
    ])->assertCreated()->json('data.id');
}

test('the owner renames a passkey and the change reaches the security audit trail', function (): void {
    $id = renameTestPasskey($this, $this->user);

    $this->withHeaders($this->headers)->patchJson("/api/v1/auth/passkeys/{$id}", ['name' => '  YubiKey 5C  '])
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.name', 'YubiKey 5C')
        ->assertJsonStructure(['data' => ['id', 'name', 'aaguid', 'created_at', 'last_used_at']])
        ->assertJsonMissingPath('data.public_key')
        ->assertJsonMissingPath('data.credential_id');

    expect(WebAuthnCredential::query()->whereKey($id)->value('name'))->toBe('YubiKey 5C');

    $this->getJson('/api/v1/auth/passkeys')->assertOk()->assertJsonPath('data.0.name', 'YubiKey 5C');

    $entry = AuditLedgerEntry::query()->where('action', 'ACCESS.SECURITY.PASSKEY_RENAMED')->sole();
    expect($entry->resource_id)->toBe((string) $this->user->id)
        ->and($entry->payload_after)->toMatchArray(['credentialId' => $id])
        ->and(json_encode($entry->payload_after))->not->toContain('YubiKey');
});

test('the event is dispatched with the tenant, the user and the credential', function (): void {
    $id = renameTestPasskey($this, $this->user);
    Event::fake([PasskeyRenamed::class]);

    $this->withHeaders($this->headers)->patchJson("/api/v1/auth/passkeys/{$id}", ['name' => 'Phone'])->assertOk();

    Event::assertDispatched(PasskeyRenamed::class, fn (PasskeyRenamed $event): bool => $event->tenantId === $this->tenant->id
        && $event->userId === (string) $this->user->id
        && $event->credentialId === $id);
});

test('the name is validated', function (): void {
    $id = renameTestPasskey($this, $this->user);

    foreach ([null, '', '   ', str_repeat('a', 121), ['x']] as $name) {
        $this->withHeaders($this->headers)->patchJson("/api/v1/auth/passkeys/{$id}", ['name' => $name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    $this->withHeaders($this->headers)->patchJson("/api/v1/auth/passkeys/{$id}", [])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(WebAuthnCredential::query()->whereKey($id)->value('name'))->toBe('Laptop');
});

test('only the owner can rename a passkey, and a revoked or unknown one is not found', function (): void {
    $id = renameTestPasskey($this, $this->user);

    $other = MfaScenario::member($this->tenant);
    Sanctum::actingAs($other);
    $this->withHeaders($this->headers)->patchJson("/api/v1/auth/passkeys/{$id}", ['name' => 'Stolen'])->assertNotFound();

    $stranger = MfaScenario::member(MfaScenario::tenant('Altra Azienda'));
    Sanctum::actingAs($stranger);
    $this->withHeaders(['X-Tenant-Id' => $stranger->tenants()->first()->id])->patchJson("/api/v1/auth/passkeys/{$id}", ['name' => 'Stolen'])->assertNotFound();

    expect(WebAuthnCredential::query()->whereKey($id)->value('name'))->toBe('Laptop');

    Sanctum::actingAs($this->user);
    $this->withHeaders($this->headers)->patchJson('/api/v1/auth/passkeys/'.Str::uuid(), ['name' => 'Ghost'])->assertNotFound();
    $this->withHeaders($this->headers)->patchJson('/api/v1/auth/passkeys/not-a-uuid', ['name' => 'Ghost'])->assertNotFound();

    $this->withHeaders($this->headers)->deleteJson("/api/v1/auth/passkeys/{$id}")->assertOk();
    $this->withHeaders($this->headers)->patchJson("/api/v1/auth/passkeys/{$id}", ['name' => 'Late'])->assertNotFound();
});

test('an unauthenticated request is refused', function (): void {
    $this->patchJson('/api/v1/auth/passkeys/'.Str::uuid(), ['name' => 'x'])->assertUnauthorized();
});
