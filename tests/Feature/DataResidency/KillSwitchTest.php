<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Auth\Totp;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\DataResidency\Application\Commands\RotateOrRevokeByokCustomerMasterKeyCommand;
use Modules\DataResidency\Application\Handlers\RotateOrRevokeByokCustomerMasterKeyHandler;
use Modules\DataResidency\Domain\Enums\KeyLifecycleAction;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Domain\Exceptions\TenantCryptoShreddedOrSuspendedException;
use Modules\DataResidency\Infrastructure\Crypto\EncryptionContext;
use Modules\DataResidency\Infrastructure\Crypto\ShortLivedDekMemoryCache;
use Modules\DataResidency\Infrastructure\Crypto\TenantEnvelopeEncryptor;
use Modules\DataResidency\Infrastructure\Models\ByokKeyAccessAudit;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;

require_once __DIR__.'/DataResidencyTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Acts as an owner with a confirmed authenticator and returns a function that yields the current code.
 */
function drOwnerWithMfa(Tenant $tenant): Closure
{
    $user = drActAs($tenant);
    $totp = app(Totp::class);
    $secret = $totp->generateSecret();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();

    return static fn (): string => $totp->codeAt($secret, time());
}

/**
 * @return array<string, mixed>
 */
function drKillPayload(string $action, string $phrase, string $code): array
{
    return ['action' => $action, 'reason' => 'Suspected compromise of the application servers', 'confirmation_phrase' => $phrase, 'mfa_code' => $code];
}

it('suspends key access: the data becomes unreadable at once and the API answers 423', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = new EncryptionContext($tenant->id, null, 'invoices', 'r1');
    $payload = $encryptor->encrypt('confidential', $context);
    $code = drOwnerWithMfa($tenant);

    $this->postJson("/api/v1/sovereignty/byok/{$envelope->id}/kill-switch", drKillPayload('SUSPEND', 'SUSPEND KEY ACCESS', $code()))
        ->assertOk()->assertJsonPath('data.status', 'SUSPENDED');

    expect(fn () => $encryptor->decrypt($payload, $context))->toThrow(TenantCryptoShreddedOrSuspendedException::class)
        ->and(fn () => $encryptor->encrypt('new', $context))->toThrow(TenantCryptoShreddedOrSuspendedException::class);

    // Every other API of the tenant is locked, while the sovereignty API stays reachable for the CISO.
    $this->getJson('/api/v1/kpi-dashboards/catalog')->assertStatus(423)->assertJsonPath('invalid_params.0.reason', 'TENANT_CRYPTO_SHREDDED_OR_SUSPENDED');
    $this->getJson('/api/v1/sovereignty/dashboard')->assertOk()->assertJsonPath('data.key_envelopes.0.status', 'SUSPENDED');

    $audit = ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('occurred_at')->orderBy('id')->get();
    expect($audit->pluck('operation')->all())->toContain('KILL_SWITCH_SUSPEND')->and($audit->pluck('operation')->all())->toContain('ACCESS_DENIED_REVOKED')
        ->and($audit->firstWhere('operation', 'KILL_SWITCH_SUSPEND')->actor_type)->toBe('CISO')
        ->and(app(ByokAuditLogger::class)->verifyChain($tenant->id)['valid'])->toBeTrue();
});

it('crypto-shreds the data key for good', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = new EncryptionContext($tenant->id, null, 'invoices', 'r1');
    $payload = $encryptor->encrypt('confidential', $context);
    $code = drOwnerWithMfa($tenant);

    $this->postJson("/api/v1/sovereignty/byok/{$envelope->id}/kill-switch", drKillPayload('CRYPTO_SHRED', 'PERMANENTLY SHRED', $code()))
        ->assertOk()->assertJsonPath('data.status', 'REVOKED_CRYPTO_SHREDDED');

    $fresh = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->findOrFail($envelope->id);
    expect($fresh->wrapped_dek_ciphertext)->toBeNull()->and($fresh->dek_cache_ttl_seconds)->toBe(0)->and($fresh->revoked_at)->not->toBeNull()
        ->and(fn () => $encryptor->decrypt($payload, $context))->toThrow(TenantCryptoShreddedOrSuspendedException::class)
        ->and(ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('operation', 'KILL_SWITCH_SHRED')->count())->toBe(1);

    // A suspended envelope can still be escalated to a shred; a shredded one cannot be touched again.
    expect(fn () => app(RotateOrRevokeByokCustomerMasterKeyHandler::class)->handle(new RotateOrRevokeByokCustomerMasterKeyCommand($tenant->id, $envelope->id, (string) Str::uuid(), KeyLifecycleAction::CryptoShred, 'again')))
        ->toThrow(DataResidencyException::class, 'already locked');
});

it('purges the cached keys on every node', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant, ttl: 600);
    $nodeA = app(ShortLivedDekMemoryCache::class);
    $nodeB = new ShortLivedDekMemoryCache;

    $nodeA->put($envelope->id, random_bytes(32), 600);
    $nodeB->put($envelope->id, random_bytes(32), 600);
    expect($nodeA->get($envelope->id))->not->toBeNull()->and($nodeB->get($envelope->id))->not->toBeNull();

    app(RotateOrRevokeByokCustomerMasterKeyHandler::class)->handle(new RotateOrRevokeByokCustomerMasterKeyCommand($tenant->id, $envelope->id, (string) Str::uuid(), KeyLifecycleAction::Suspend, 'incident'));

    expect($nodeA->has($envelope->id))->toBeFalse()->and($nodeB->get($envelope->id))->toBeNull()->and($nodeB->has($envelope->id))->toBeFalse();
});

it('guards the kill-switch with the typed phrase, the step-up code and the owner role', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant);
    $code = drOwnerWithMfa($tenant);
    $url = "/api/v1/sovereignty/byok/{$envelope->id}/kill-switch";

    $this->postJson($url, drKillPayload('SUSPEND', 'wrong phrase', $code()))->assertStatus(422);
    $this->postJson($url, drKillPayload('SUSPEND', 'SUSPEND KEY ACCESS', '000000'))->assertForbidden();
    $this->postJson($url, drKillPayload('CRYPTO_SHRED', 'SUSPEND KEY ACCESS', $code()))->assertStatus(422);
    $this->postJson($url, ['action' => 'SUSPEND'])->assertStatus(422);

    drActAs($tenant, 'admin');
    $this->postJson($url, drKillPayload('SUSPEND', 'SUSPEND KEY ACCESS', '123456'))->assertForbidden();

    expect(CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->findOrFail($envelope->id)->status->value)->toBe('ACTIVE');
});

it('refuses the kill-switch to a user without a second factor', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant);
    drActAs($tenant);

    $this->postJson("/api/v1/sovereignty/byok/{$envelope->id}/kill-switch", drKillPayload('SUSPEND', 'SUSPEND KEY ACCESS', '123456'))->assertForbidden();
});

it('does not touch another tenant when one suspends its key', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant);
    $other = drTenant();
    drEnvelope($other);
    $code = drOwnerWithMfa($tenant);

    $this->postJson("/api/v1/sovereignty/byok/{$envelope->id}/kill-switch", drKillPayload('SUSPEND', 'SUSPEND KEY ACCESS', $code()))->assertOk();

    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = new EncryptionContext($other->id, null, 'invoices', 'r1');
    expect($encryptor->decrypt($encryptor->encrypt('still readable', $context), $context))->toBe('still readable');

    app(TenantContext::class)->clear();
    drOwnerWithMfa($other);
    $this->getJson('/api/v1/kpi-dashboards/catalog')->assertOk();
});

it('keeps an append-only, hash-chained KMS log and detects tampering', function (): void {
    $tenant = drTenant();
    $envelope = drEnvelope($tenant);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = new EncryptionContext($tenant->id, null, 'invoices', 'r1');
    $encryptor->decrypt($encryptor->encrypt('x', $context), $context);
    $logger = app(ByokAuditLogger::class);

    $entries = ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('occurred_at')->orderBy('id')->get();
    expect($entries->count())->toBeGreaterThanOrEqual(4)
        ->and($entries->first()->previous_entry_hash)->toBe(ByokAuditLogger::GENESIS)
        ->and($entries[1]->previous_entry_hash)->toBe($entries[0]->entry_hash)
        ->and($logger->verifyChain($tenant->id))->toMatchArray(['valid' => true, 'broken_at' => null]);

    expect(fn () => $entries[0]->update(['outcome' => 'DENIED']))->toThrow(LogicException::class)
        ->and(fn () => $entries[0]->delete())->toThrow(LogicException::class);

    // An edit that bypasses the application (a direct SQL update) breaks the chain at that entry.
    DB::table('core_byok_key_access_audit')->where('id', $entries[1]->id)->update(['latency_ms' => 99999]);
    $check = $logger->verifyChain($tenant->id);
    expect($check['valid'])->toBeFalse()->and($check['broken_at'])->toBe($entries[1]->id);

    drActAs($tenant);
    $this->getJson('/api/v1/sovereignty/byok/audit-logs?per_page=2')->assertOk()->assertJsonPath('meta.chain.valid', false)->assertJsonCount(2, 'data');
    expect($envelope->id)->not->toBeEmpty();
});
