<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Infrastructure\Models\SovereigntyComplianceAttestation;
use Modules\DataResidency\Infrastructure\Services\AttestationBundleFactory;
use Modules\DataResidency\Infrastructure\Services\AttestationSigner;

require_once __DIR__.'/DataResidencyTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    drSeedCatalogue();
});

it('issues a signed certificate whose topology matches the physical nodes', function (): void {
    $tenant = drTenant();
    $entity = drLegalEntity($tenant, 'LUG', 'Lugano Holding SA', 'CH');
    drEnforce($tenant, 'ch-lug', JurisdictionZone::StrictChOnly, $entity);
    drEnvelope($tenant, entityId: null);
    drActAs($tenant);

    $response = $this->postJson('/api/v1/sovereignty/attestations/generate', ['legal_entity_id' => $entity])->assertCreated();
    $bundle = $response->json('data.bundle');
    $topology = $bundle['payload']['physical_topology']['nodes'];

    expect($response->json('data.attestation.certificate_number'))->toMatch('/^FF-SOV-CH-\d{4}-000001$/')
        ->and($bundle['algorithm'])->toBe('Ed25519')
        ->and($bundle['payload']['jurisdiction_zone'])->toBe('Strict-CH-Only')
        ->and($bundle['payload']['compliance_frameworks'])->toContain('nLPD Art. 16-17', 'FINMA Circ. 2018/3', 'Art. 321 CP')
        ->and($topology['postgres_primary'])->toMatchArray(['node_code' => 'ch-lug-01', 'city' => 'Lugano', 'canton_or_region' => 'TI', 'country_iso2' => 'CH'])
        ->and($topology['postgres_primary']['certifications'])->toContain('ISO-27001', 'FINMA-2018-3')
        ->and(collect($topology['postgres_replica'])->pluck('node_code')->all())->toBe(['ch-lug-02'])
        ->and(collect($topology)->except('postgres_replica')->pluck('country_iso2')->unique()->all())->toBe(['CH']);

    // Every node of the topology is the node the policy binds: no value comes from anywhere else.
    expect($topology['s3_dms']['node_code'])->toBe('ch-lug-s3-01')->and($topology['queue_broker']['node_code'])->toBe('ch-lug-q-01')
        ->and($bundle['payload']['byok_status']['mode'])->toBe('Platform-Managed')
        ->and($bundle['payload']['byok_status']['kms_integrity_last_30_days']['valid'])->toBeTrue();

    $json = json_encode($bundle);
    expect($json)->not->toContain('wrapped_dek')->and($json)->not->toContain('credentials');
});

it('passes the cryptographic verification and fails on any change', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($tenant);
    $id = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.id');

    $this->getJson("/api/v1/sovereignty/attestations/{$id}")->assertOk()->assertJsonPath('data.signature_valid', true);

    $attestation = SovereigntyComplianceAttestation::query()->withoutGlobalScopes()->findOrFail($id);
    $factory = app(AttestationBundleFactory::class);
    $bundle = $factory->bundle($attestation);

    // The auditor holds only the bundle and the platform public key.
    $canonical = AttestationSigner::canonicalize($bundle['payload']);
    expect(hash('sha256', $canonical))->toBe($bundle['sha256_payload_hash'])
        ->and(app(AttestationSigner::class)->verify($canonical, $bundle['signature'], $bundle['signer_public_key']))->toBeTrue()
        ->and($bundle['signer_key_fingerprint'])->toBe(AttestationSigner::fingerprint((string) base64_decode($bundle['signer_public_key'], true)))
        ->and($factory->verify($attestation, $bundle))->toBeTrue();

    $forged = $bundle;
    $forged['payload']['physical_topology']['nodes']['postgres_primary']['city'] = 'Frankfurt';
    expect($factory->verify($attestation, $forged))->toBeFalse();

    $other = $bundle;
    $other['signer_public_key'] = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
    expect($factory->verify($attestation, $other))->toBeFalse();

    // A direct database edit of the stored snapshot is caught too.
    DB::table('core_sovereignty_attestations')->where('id', $id)->update(['jurisdiction_zone' => 'Strict-IT-Only']);
    expect($factory->verify($attestation->fresh()))->toBeFalse();
    $this->getJson("/api/v1/sovereignty/attestations/{$id}")->assertJsonPath('data.signature_valid', false);
});

it('signs with the configured platform key and derives a stable fingerprint', function (): void {
    config(['data_residency.attestation_signing_key' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SIGN_SEEDBYTES))]);
    $signer = app(AttestationSigner::class);

    $one = $signer->sign('payload');
    $two = $signer->sign('payload');

    expect($one['fingerprint'])->toBe($two['fingerprint'])->and($one['signature'])->toBe($two['signature'])->and($one['fingerprint'])->toStartWith('ed25519:')
        ->and($signer->verify('payload', $one['signature'], $one['public_key']))->toBeTrue()
        ->and($signer->verify('payload!', $one['signature'], $one['public_key']))->toBeFalse()
        ->and($signer->verify('payload', 'not-base64!!', $one['public_key']))->toBeFalse();
});

it('canonicalizes the payload independently of key order', function (): void {
    expect(AttestationSigner::canonicalize(['b' => 1, 'a' => ['y' => 2, 'x' => 3], 'list' => [3, 1]]))
        ->toBe(AttestationSigner::canonicalize(['a' => ['x' => 3, 'y' => 2], 'list' => [3, 1], 'b' => 1]))
        ->toBe('{"a":{"x":3,"y":2},"b":1,"list":[3,1]}');
});

it('numbers the certificates per region and year', function (): void {
    $swiss = drTenant();
    drEnforce($swiss, 'ch-zrh', JurisdictionZone::StrictChOnly);
    $italian = drTenant();
    drEnforce($italian, 'it-mil', JurisdictionZone::StrictItOnly);

    drActAs($swiss);
    $first = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.certificate_number');
    $second = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.certificate_number');
    drActAs($italian);
    $milan = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.certificate_number');

    expect($first)->toEndWith('-000001')->and($second)->toEndWith('-000002')->and($milan)->toStartWith('FF-SOV-IT-')->and($milan)->toEndWith('-000001');
});

it('downloads the certificate as a PDF in the requested language', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'it-mil', JurisdictionZone::StrictItOnly);
    drActAs($tenant);
    $id = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.id');

    $pdf = $this->get("/api/v1/sovereignty/attestations/{$id}/pdf?locale=de");

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF')->and((string) $pdf->headers->get('Content-Disposition'))->toContain('FF-SOV-IT-');
});

it('refuses an attestation without a policy and hides the certificates of other tenants', function (): void {
    $tenant = drTenant();
    drActAs($tenant);
    $this->postJson('/api/v1/sovereignty/attestations/generate')->assertStatus(422);

    $other = drTenant();
    drEnforce($other, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($other);
    $id = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.id');

    drActAs($tenant);
    $this->getJson("/api/v1/sovereignty/attestations/{$id}")->assertNotFound();
    $this->get("/api/v1/sovereignty/attestations/{$id}/pdf")->assertNotFound();
});

it('keeps the certificates append-only', function (): void {
    $tenant = drTenant();
    drEnforce($tenant, 'ch-zrh', JurisdictionZone::StrictChOnly);
    drActAs($tenant);
    $id = $this->postJson('/api/v1/sovereignty/attestations/generate')->assertCreated()->json('data.attestation.id');
    $attestation = SovereigntyComplianceAttestation::query()->withoutGlobalScopes()->findOrFail($id);

    expect(fn () => $attestation->update(['jurisdiction_zone' => 'Strict-IT-Only']))->toThrow(LogicException::class)
        ->and(fn () => $attestation->delete())->toThrow(LogicException::class);
});
