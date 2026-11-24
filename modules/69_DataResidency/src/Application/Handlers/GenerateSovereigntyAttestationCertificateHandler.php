<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\DataResidency\Application\Queries\GenerateSovereigntyAttestationCertificateQuery;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\SovereignServiceType;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Infrastructure\Models\CrossBorderBlockedAttempt;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\SovereigntyComplianceAttestation;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;
use Modules\DataResidency\Infrastructure\Services\AttestationBundleFactory;
use Modules\DataResidency\Infrastructure\Services\AttestationSigner;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;
use Modules\DataResidency\Infrastructure\Services\ResidencyPolicyResolver;

/**
 * Issues the Data Sovereignty Attestation Certificate: a snapshot of the live physical topology (data centers, cantons and
 * regions, certifications), the key status (no secret material), the egress firewall metrics and the integrity of the last
 * 30 days of the KMS access log, signed with Ed25519 over the canonical JSON.
 */
final class GenerateSovereigntyAttestationCertificateHandler
{
    public function __construct(
        private readonly ResidencyPolicyResolver $policies,
        private readonly ByokAuditLogger $audit,
        private readonly AttestationSigner $signer,
        private readonly AttestationBundleFactory $bundles,
    ) {}

    /**
     * @return array{attestation: SovereigntyComplianceAttestation, bundle: array<string, mixed>}
     */
    public function handle(GenerateSovereigntyAttestationCertificateQuery $query): array
    {
        $policy = $this->policies->resolve($query->tenantId, $query->legalEntityId)
            ?? throw new DataResidencyException('There is no residency policy to attest.');

        $now = Carbon::now()->utc()->startOfSecond();
        $since = $now->copy()->subDays(30);

        $attestation = new SovereigntyComplianceAttestation([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $query->tenantId,
            'legal_entity_id' => $policy->legal_entity_id,
            'certificate_number' => $this->certificateNumber($policy->jurisdiction_zone, $now),
            'jurisdiction_zone' => $policy->jurisdiction_zone->value,
            'topology_snapshot_json' => [
                'nodes' => $this->topology($policy),
                'egress_firewall' => $this->egress($query->tenantId, $policy, $since),
            ],
            'byok_snapshot_json' => $this->byok($query->tenantId, $policy, $since),
            'compliance_frameworks_json' => $policy->jurisdiction_zone->complianceFrameworks(),
            'issued_at' => $now,
            'valid_until' => $now->copy()->addDays((int) config('data_residency.attestation_validity_days', 90)),
        ]);

        $canonical = AttestationSigner::canonicalize($this->bundles->payloadOf($attestation));
        $signed = $this->signer->sign($canonical);

        $attestation->forceFill([
            'sha256_payload_hash' => hash('sha256', $canonical),
            'digital_signature' => $signed['signature'],
            'signer_key_fingerprint' => $signed['fingerprint'],
        ])->save();

        return ['attestation' => $attestation, 'bundle' => $this->bundles->bundle($attestation)];
    }

    /**
     * @return array<string, mixed>
     */
    private function topology(TenantDataResidencyPolicy $policy): array
    {
        $replicas = SovereignRegionNode::query()->withoutGlobalScopes()->whereIn('id', $policy->replica_db_node_ids_json ?? [])->orderBy('node_code')->get();

        return [
            SovereignServiceType::PostgresPrimary->value => $policy->primaryDbNode->coordinates()->toArray(),
            SovereignServiceType::PostgresReplica->value => $replicas->map(static fn (SovereignRegionNode $node): array => $node->coordinates()->toArray())->all(),
            SovereignServiceType::S3Dms->value => $policy->s3StorageNode->coordinates()->toArray(),
            SovereignServiceType::Pgvector->value => $policy->vectorDbNode->coordinates()->toArray(),
            SovereignServiceType::AiInference->value => $policy->aiInferenceNode->coordinates()->toArray(),
            SovereignServiceType::BackupVault->value => $policy->backupNode->coordinates()->toArray(),
            SovereignServiceType::QueueBroker->value => $policy->queueNode->coordinates()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function egress(string $tenantId, TenantDataResidencyPolicy $policy, Carbon $since): array
    {
        $blocked = CrossBorderBlockedAttempt::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('occurred_at', '>=', $since)->count();

        return [
            'strict_egress_block' => $policy->enforce_strict_egress_block,
            'aggregated_non_pii_consolidation' => $policy->allow_aggregated_non_pii_consolidation,
            'blocked_transfers_last_30_days' => $blocked,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function byok(string $tenantId, TenantDataResidencyPolicy $policy, Carbon $since): array
    {
        $envelope = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where(static fn ($query) => $policy->legal_entity_id === null ? $query->whereNull('legal_entity_id') : $query->where('legal_entity_id', $policy->legal_entity_id)->orWhereNull('legal_entity_id'))
            ->orderByRaw('legal_entity_id is null')->first();

        $integrity = $this->audit->verifyChain($tenantId, $since);

        if ($envelope === null) {
            return ['mode' => 'none', 'status' => 'NOT_CONFIGURED', 'kms_integrity_last_30_days' => $integrity];
        }

        return [
            'mode' => $envelope->key_management_mode->value,
            'provider' => $envelope->provider_type->value,
            'status' => $envelope->status->value,
            'key_version' => $envelope->current_key_version,
            'dek_cache_ttl_seconds' => $envelope->dek_cache_ttl_seconds,
            'last_rotated_at' => $envelope->last_rotated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'next_rotation_due_at' => $envelope->next_rotation_due_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'kms_integrity_last_30_days' => $integrity,
        ];
    }

    private function certificateNumber(JurisdictionZone $zone, Carbon $now): string
    {
        $region = match ($zone) {
            JurisdictionZone::StrictChOnly => 'CH',
            JurisdictionZone::StrictItOnly => 'IT',
            JurisdictionZone::EeaPlusAdequacyCh => 'EU',
        };

        $sequence = SovereigntyComplianceAttestation::query()->withoutGlobalScopes()->where('certificate_number', 'like', "FF-SOV-{$region}-{$now->year}-%")->count() + 1;

        return sprintf('FF-SOV-%s-%d-%06d', $region, $now->year, $sequence);
    }
}
