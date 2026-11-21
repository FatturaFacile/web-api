<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

use Modules\DataResidency\Infrastructure\Models\SovereigntyComplianceAttestation;

/**
 * The verifiable JSON bundle of an attestation and its check: the canonical payload is rebuilt from the stored row only, so
 * an auditor holding the bundle and the platform public key can verify it without access to the system.
 */
final class AttestationBundleFactory
{
    public function __construct(private readonly AttestationSigner $signer) {}

    /**
     * @return array<string, mixed>
     */
    public function payloadOf(SovereigntyComplianceAttestation $attestation): array
    {
        return [
            'certificate_number' => $attestation->certificate_number,
            'tenant_id' => $attestation->tenant_id,
            'legal_entity_id' => $attestation->legal_entity_id,
            'jurisdiction_zone' => $attestation->jurisdiction_zone,
            'issued_at' => $attestation->issued_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'valid_until' => $attestation->valid_until->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'physical_topology' => $attestation->topology_snapshot_json,
            'byok_status' => $attestation->byok_snapshot_json,
            'compliance_frameworks' => $attestation->compliance_frameworks_json,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bundle(SovereigntyComplianceAttestation $attestation): array
    {
        return [
            'algorithm' => 'Ed25519',
            'payload' => $this->payloadOf($attestation),
            'sha256_payload_hash' => $attestation->sha256_payload_hash,
            'signature' => $attestation->digital_signature,
            'signer_key_fingerprint' => $attestation->signer_key_fingerprint,
            'signer_public_key' => $this->signer->publicKey(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $bundle  A bundle to check instead of the stored row (as an auditor would)
     */
    public function verify(SovereigntyComplianceAttestation $attestation, ?array $bundle = null): bool
    {
        $payload = $bundle['payload'] ?? $this->payloadOf($attestation);
        $canonical = AttestationSigner::canonicalize((array) $payload);
        $publicKey = (string) ($bundle['signer_public_key'] ?? $this->signer->publicKey());

        return hash_equals($attestation->sha256_payload_hash, hash('sha256', $canonical))
            && hash_equals($attestation->signer_key_fingerprint, AttestationSigner::fingerprint((string) base64_decode($publicKey, true)))
            && $this->signer->verify($canonical, $attestation->digital_signature, $publicKey);
    }
}
