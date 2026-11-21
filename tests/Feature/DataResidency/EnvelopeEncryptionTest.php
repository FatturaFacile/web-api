<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\DataResidency\Application\Commands\RotateOrRevokeByokCustomerMasterKeyCommand;
use Modules\DataResidency\Application\Handlers\RotateOrRevokeByokCustomerMasterKeyHandler;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\KeyLifecycleAction;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Crypto\EncryptionContext;
use Modules\DataResidency\Infrastructure\Crypto\TenantEnvelopeEncryptor;
use Modules\DataResidency\Infrastructure\Models\ByokKeyAccessAudit;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

require_once __DIR__.'/DataResidencyTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    drSeedCatalogue();
});

/** A fake Securosys gateway: wrapping prefixes the key version, so a rotation is visible in the wrapped key. */
function drFakeSecurosys(): void
{
    Http::fake(static function (Request $request) {
        $data = $request->data();
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/wrap') => Http::response(['wrapped' => $data['keyVersion'].'|'.$data['payload']]),
            str_ends_with($url, '/unwrap') => Http::response(['payload' => explode('|', (string) $data['wrapped'], 2)[1]]),
            str_ends_with($url, '/versions') => Http::response(['keyVersion' => 'v2']),
            default => Http::response([], 404),
        };
    });
}

/** A fake AWS KMS: Encrypt prefixes the blob, Decrypt strips it. */
function drFakeAws(): void
{
    Http::fake(static function (Request $request) {
        $data = $request->data();

        return match ($request->header('X-Amz-Target')[0] ?? '') {
            'TrentService.Encrypt' => Http::response(['CiphertextBlob' => 'C'.$data['Plaintext']]),
            'TrentService.Decrypt' => Http::response(['Plaintext' => substr((string) $data['CiphertextBlob'], 1)]),
            default => Http::response([], 400),
        };
    });
}

function drCtx(string $tenantId, ?string $entityId = null, string $table = 'invoices', string $record = 'rec-1'): EncryptionContext
{
    return new EncryptionContext($tenantId, $entityId, $table, $record);
}

function drUnwraps(): int
{
    return Http::recorded(static fn (Request $request): bool => str_ends_with($request->url(), '/unwrap'))->count();
}

it('encrypts and decrypts with AES-256-GCM under the platform key and a fresh nonce per record', function (): void {
    $tenant = drTenant();
    drEnvelope($tenant);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = drCtx($tenant->id);

    $one = $encryptor->encrypt('IBAN CH93 0076 2011 6238 5295 7', $context);
    $two = $encryptor->encrypt('IBAN CH93 0076 2011 6238 5295 7', $context);

    expect($one)->toStartWith('ffb1:')->and($one)->not->toBe($two)->and($one)->not->toContain('CH93')
        ->and($encryptor->decrypt($one, $context))->toBe('IBAN CH93 0076 2011 6238 5295 7');
});

it('binds a ciphertext to its tenant, legal entity, table and record', function (EncryptionContext $tampered): void {
    $tenant = drTenant();
    drEnvelope($tenant);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $payload = $encryptor->encrypt('secret', drCtx($tenant->id, null, 'invoices', 'rec-1'));

    expect(fn () => $encryptor->decrypt($payload, $tampered))->toThrow(DataResidencyException::class, 'authentication');
})->with([
    'another record' => fn () => drCtx('', null, 'invoices', 'rec-2'),
    'another table' => fn () => drCtx('', null, 'payslips', 'rec-1'),
    'another legal entity' => fn () => drCtx('', (string) Str::uuid(), 'invoices', 'rec-1'),
]);

it('refuses a ciphertext moved to another tenant', function (): void {
    $tenant = drTenant();
    drEnvelope($tenant);
    $other = drTenant();
    drEnvelope($other);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $payload = $encryptor->encrypt('secret', drCtx($tenant->id));

    // Re-pointing the payload at the envelope of the other tenant is detected by the AAD even with a valid envelope.
    $forged = explode(':', $payload, 3);
    $forged[1] = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->value('id');

    expect(fn () => $encryptor->decrypt(implode(':', $forged), drCtx($other->id)))->toThrow(DataResidencyException::class)
        ->and(fn () => $encryptor->decrypt($payload, drCtx($other->id)))->toThrow(DataResidencyException::class);
});

it('detects a flipped bit and a malformed payload', function (): void {
    $tenant = drTenant();
    drEnvelope($tenant);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = drCtx($tenant->id);
    $payload = $encryptor->encrypt('secret', $context);

    [$prefix, $id, $body] = explode(':', $payload, 3);
    $raw = (string) base64_decode($body, true);
    $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";

    expect(fn () => $encryptor->decrypt("{$prefix}:{$id}:".base64_encode($raw), $context))->toThrow(DataResidencyException::class)
        ->and(fn () => $encryptor->decrypt('not-a-ciphertext', $context))->toThrow(DataResidencyException::class, 'Malformed');
});

it('wraps the data key in the customer Securosys HSM and never stores it raw', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drFakeSecurosys();

    $envelope = drEnvelope($tenant, KeyManagementMode::Byok, KmsProviderType::SecurosysPrimusHsm, null, 'https://hsm.example.ch/v1/keys/ff', ['bearer_token' => 'tok', 'country_iso2' => 'CH']);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $payload = $encryptor->encrypt('payroll', drCtx($tenant->id, null, 'payslips', 'p1'));

    expect($envelope->wrapped_dek_ciphertext)->toStartWith('initial|')
        ->and($encryptor->decrypt($payload, drCtx($tenant->id, null, 'payslips', 'p1')))->toBe('payroll');

    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer tok'));
    // The stored credentials are encrypted and never returned by the API.
    expect($envelope->kms_credentials_encrypted)->not->toContain('tok');
});

it('wraps the data key in the customer AWS KMS in Zurich with a signed request', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drFakeAws();

    drEnvelope($tenant, KeyManagementMode::Byok, KmsProviderType::AwsKms, null, 'arn:aws:kms:eu-central-2:123456789012:key/abc', ['access_key_id' => 'AKIATEST', 'secret_access_key' => 'secret']);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $payload = $encryptor->encrypt('dms file', drCtx($tenant->id));

    expect($encryptor->decrypt($payload, drCtx($tenant->id)))->toBe('dms file');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://kms.eu-central-2.amazonaws.com/'
        && str_starts_with($request->header('Authorization')[0] ?? '', 'AWS4-HMAC-SHA256 Credential=AKIATEST/')
        && str_contains($request->header('Authorization')[0] ?? '', '/eu-central-2/kms/aws4_request'));
});

it('refuses a customer KMS outside the jurisdiction zone', function (KmsProviderType $provider, string $uri, array $credentials, string $site, JurisdictionZone $zone): void {
    $tenant = drTenant();
    drEnforce($tenant, $site, $zone);
    Http::fake();

    expect(fn () => drEnvelope($tenant, KeyManagementMode::Byok, $provider, null, $uri, $credentials))->toThrow(CrossBorderResidencyViolationException::class);
    Http::assertNothingSent();
})->with([
    'AWS Milan under Strict-CH-Only' => [KmsProviderType::AwsKms, 'arn:aws:kms:eu-south-1:1:key/a', [], 'ch-zrh', JurisdictionZone::StrictChOnly],
    'AWS Virginia under Strict-IT-Only' => [KmsProviderType::AwsKms, 'arn:aws:kms:us-east-1:1:key/a', [], 'it-mil', JurisdictionZone::StrictItOnly],
    'Azure Italy North under Strict-CH-Only' => [KmsProviderType::AzureKeyVault, 'https://v.vault.azure.net/keys/k', ['region' => 'italynorth'], 'ch-zrh', JurisdictionZone::StrictChOnly],
    'Google Zurich under Strict-IT-Only' => [KmsProviderType::GoogleCloudEkm, 'projects/p/locations/europe-west6/keyRings/r/cryptoKeys/k', [], 'it-mil', JurisdictionZone::StrictItOnly],
    'self-hosted Vault without a declared country' => [KmsProviderType::HashicorpVault, 'https://vault.example.com/v1/transit/keys/ff', [], 'ch-zrh', JurisdictionZone::StrictChOnly],
]);

it('requires a residency policy before bringing your own key and a matching provider for the mode', function (): void {
    $tenant = drTenant();

    expect(fn () => drEnvelope($tenant, KeyManagementMode::Byok, KmsProviderType::AwsKms, null, 'arn:aws:kms:eu-central-2:1:key/a'))->toThrow(DataResidencyException::class, 'residency policy');
    expect(fn () => drEnvelope($tenant, KeyManagementMode::PlatformManaged, KmsProviderType::AwsKms, null, 'arn'))->toThrow(DataResidencyException::class);
    expect(fn () => drEnvelope($tenant, KeyManagementMode::Hyok, KmsProviderType::Platform))->toThrow(DataResidencyException::class);
});

it('caches the unwrapped key for a BYOK envelope but never for HYOK', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drFakeSecurosys();

    $byok = drEnvelope($tenant, KeyManagementMode::Byok, KmsProviderType::SecurosysPrimusHsm, null, 'https://hsm.example.ch/v1/keys/ff', ['country_iso2' => 'CH'], 600);
    expect($byok->dek_cache_ttl_seconds)->toBe(600);

    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = drCtx($tenant->id);
    $payload = $encryptor->encrypt('x', $context);
    $encryptor->decrypt($payload, $context);
    $encryptor->decrypt($payload, $context);

    // One unwrap fills the cache (the configuration handshake unwraps once more); the later calls are cache hits.
    expect(drUnwraps())->toBe(2)
        ->and(ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('operation', 'CACHE_HIT')->count())->toBe(2);

    $other = drTenant();
    drEnforce($other, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $hyok = drEnvelope($other, KeyManagementMode::Hyok, KmsProviderType::SecurosysPrimusHsm, null, 'https://hsm.example.ch/v1/keys/other', ['country_iso2' => 'CH'], 600);
    expect($hyok->dek_cache_ttl_seconds)->toBe(0);

    $before = drUnwraps();
    $hyokContext = drCtx($other->id);
    $hyokPayload = $encryptor->encrypt('x', $hyokContext);
    $encryptor->decrypt($hyokPayload, $hyokContext);
    $encryptor->decrypt($hyokPayload, $hyokContext);

    // Encrypt and both decrypts each unwrap in the HSM: nothing is kept in memory.
    expect(drUnwraps() - $before)->toBe(3)
        ->and(ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->where('operation', 'UNWRAP_DEK')->count())->toBeGreaterThanOrEqual(3);
});

it('tests the HSM handshake without persisting anything', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($tenant);
    drFakeSecurosys();

    $this->postJson('/api/v1/sovereignty/byok/configure', [
        'key_management_mode' => 'BYOK', 'provider_type' => 'SECUROSYS_PRIMUS_HSM', 'external_key_uri' => 'https://hsm.example.ch/v1/keys/ff',
        'credentials' => ['bearer_token' => 'tok', 'country_iso2' => 'CH'], 'dry_run' => true,
    ])->assertOk()->assertJsonPath('data.handshake.wrap', true)->assertJsonPath('data.handshake.unwrap', true)->assertJsonPath('data.envelope', null);

    expect(CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('answers 502 when the customer KMS is unreachable and logs it', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($tenant);
    Http::fake(static fn () => throw new ConnectionException('timeout'));

    $this->postJson('/api/v1/sovereignty/byok/configure', [
        'key_management_mode' => 'HYOK', 'provider_type' => 'SECUROSYS_PRIMUS_HSM', 'external_key_uri' => 'https://hsm.example.ch/v1/keys/ff', 'credentials' => ['country_iso2' => 'CH'],
    ])->assertStatus(502)->assertJsonPath('invalid_params.0.reason', 'KMS_UNAVAILABLE');

    expect(CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0)
        ->and(ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('outcome', 'KMS_UNREACHABLE')->count())->toBe(1);
});

it('never returns secret material through the API', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($tenant);
    drFakeSecurosys();

    $response = $this->postJson('/api/v1/sovereignty/byok/configure', [
        'key_management_mode' => 'BYOK', 'provider_type' => 'SECUROSYS_PRIMUS_HSM', 'external_key_uri' => 'https://hsm.example.ch/v1/keys/ff',
        'credentials' => ['bearer_token' => 'super-secret-token', 'country_iso2' => 'CH'],
    ])->assertOk();

    expect($response->getContent())->not->toContain('super-secret-token')->and($response->getContent())->not->toContain('wrapped_dek')->and($response->getContent())->not->toContain('initial|');
    $this->getJson('/api/v1/sovereignty/byok')->assertOk()->assertJsonPath('data.0.status', 'ACTIVE');
});

it('rotates the key-encryption key without re-encrypting the data', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drFakeSecurosys();
    $envelope = drEnvelope($tenant, KeyManagementMode::Byok, KmsProviderType::SecurosysPrimusHsm, null, 'https://hsm.example.ch/v1/keys/ff', ['country_iso2' => 'CH']);
    $encryptor = app(TenantEnvelopeEncryptor::class);
    $context = drCtx($tenant->id);
    $payload = $encryptor->encrypt('stored before the rotation', $context);

    $rotated = app(RotateOrRevokeByokCustomerMasterKeyHandler::class)->handle(new RotateOrRevokeByokCustomerMasterKeyCommand($tenant->id, $envelope->id, (string) Str::uuid(), KeyLifecycleAction::Rotate));

    expect($rotated->current_key_version)->toBe('v2')->and($rotated->status->value)->toBe('ACTIVE')
        ->and($rotated->wrapped_dek_ciphertext)->toStartWith('v2|')
        ->and($rotated->next_rotation_due_at->isFuture())->toBeTrue()
        ->and($encryptor->decrypt($payload, $context))->toBe('stored before the rotation')
        ->and(ByokKeyAccessAudit::query()->withoutGlobalScopes()->where('operation', 'ROTATE_KEK')->where('outcome', 'SUCCESS')->exists())->toBeTrue();
});

it('keeps the envelope unchanged when the KMS fails during a rotation', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drFakeSecurosys();
    $envelope = drEnvelope($tenant, KeyManagementMode::Byok, KmsProviderType::SecurosysPrimusHsm, null, 'https://hsm.example.ch/v1/keys/ff', ['country_iso2' => 'CH']);
    $wrapped = $envelope->wrapped_dek_ciphertext;

    Http::fake(static fn () => throw new ConnectionException('down'));

    expect(fn () => app(RotateOrRevokeByokCustomerMasterKeyHandler::class)->handle(new RotateOrRevokeByokCustomerMasterKeyCommand($tenant->id, $envelope->id, (string) Str::uuid(), KeyLifecycleAction::Rotate)))
        ->toThrow(KmsUnavailableException::class);

    expect($envelope->fresh()->wrapped_dek_ciphertext)->toBe($wrapped)->and($envelope->fresh()->current_key_version)->toBe('initial');
});
