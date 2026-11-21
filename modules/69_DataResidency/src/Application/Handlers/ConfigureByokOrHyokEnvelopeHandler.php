<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Handlers;

use Illuminate\Support\Facades\Crypt;
use Modules\DataResidency\Application\Commands\ConfigureByokOrHyokEnvelopeCommand;
use Modules\DataResidency\Domain\Enums\AccessOutcome;
use Modules\DataResidency\Domain\Enums\ActorType;
use Modules\DataResidency\Domain\Enums\KeyEnvelopeStatus;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KeyOperation;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Kms\KmsProviderRegistry;
use Modules\DataResidency\Infrastructure\Kms\KmsRegionPolicy;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;
use Modules\DataResidency\Infrastructure\Services\ResidencyPolicyResolver;

/**
 * Connects the customer's external KMS/HSM, proves it can wrap and unwrap, generates a random 256-bit DEK, wraps it with the
 * customer's KEK and stores only the wrapped form. The raw DEK is zeroed in memory right after.
 */
final class ConfigureByokOrHyokEnvelopeHandler
{
    /** The longest a DEK may stay cached, whatever the customer asks for. */
    public const int MAX_CACHE_TTL_SECONDS = 900;

    public function __construct(
        private readonly KmsProviderRegistry $providers,
        private readonly KmsRegionPolicy $regions,
        private readonly ResidencyPolicyResolver $policies,
        private readonly ByokAuditLogger $audit,
    ) {}

    /**
     * @return array{envelope: CustomerManagedKeyEnvelope|null, handshake: array{wrap: bool, unwrap: bool}}
     */
    public function handle(ConfigureByokOrHyokEnvelopeCommand $command): array
    {
        $this->assertProviderMatchesMode($command);

        if ($command->mode->isCustomerManaged()) {
            $policy = $this->policies->resolve($command->tenantId, $command->legalEntityId)
                ?? throw new DataResidencyException('Configure the data residency policy before bringing your own key.');
            $this->regions->assertAllowed($policy->jurisdiction_zone, $command->mode, $command->provider, $command->externalKeyUri, $command->credentials);
        }

        if (! $command->dryRun && CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $command->tenantId)->where('legal_entity_id', $command->legalEntityId)->exists()) {
            throw new DataResidencyException('A key envelope already exists for this scope: rotate it instead of configuring a new one.');
        }

        $ttl = $command->mode === KeyManagementMode::Hyok ? 0 : max(0, min($command->dekCacheTtlSeconds, self::MAX_CACHE_TTL_SECONDS));

        $envelope = new CustomerManagedKeyEnvelope;
        $envelope->forceFill([
            'tenant_id' => $command->tenantId,
            'legal_entity_id' => $command->legalEntityId,
            'key_management_mode' => $command->mode,
            'provider_type' => $command->provider,
            'external_key_uri' => $command->externalKeyUri,
            'kmip_endpoint' => $command->kmipEndpoint,
            'kms_credentials_encrypted' => $command->credentials === [] ? null : Crypt::encryptString((string) json_encode($command->credentials)),
            'current_key_version' => 'initial',
            'dek_wrap_algorithm' => 'AES-256-GCM',
            'status' => KeyEnvelopeStatus::Active,
            'dek_cache_ttl_seconds' => $ttl,
            'rotation_interval_days' => max(1, $command->rotationIntervalDays),
            'last_rotated_at' => now(),
            'next_rotation_due_at' => now()->addDays(max(1, $command->rotationIntervalDays)),
            'created_by' => $command->actorId,
            'updated_by' => $command->actorId,
        ]);

        $adapter = $this->providers->for($command->provider);
        $started = hrtime(true);

        try {
            $handshake = $adapter->verifyCapabilities($envelope);
        } catch (KmsUnavailableException $exception) {
            if (! $command->dryRun) {
                $this->audit->record($envelope, KeyOperation::KmsHealthCheck, AccessOutcome::KmsUnreachable, $this->elapsedMs($started), ActorType::User, $command->actorId);
            }

            throw $exception;
        }

        if (! $handshake['wrap'] || ! $handshake['unwrap']) {
            throw new DataResidencyException('The KMS key must allow both wrap and unwrap.');
        }

        if ($command->dryRun) {
            return ['envelope' => null, 'handshake' => $handshake];
        }

        $dek = random_bytes(32);

        try {
            $envelope->wrapped_dek_ciphertext = $adapter->wrapKey($envelope, $dek);
        } finally {
            sodium_memzero($dek);
        }

        $envelope->save();
        $this->audit->record($envelope, KeyOperation::KmsHealthCheck, AccessOutcome::Success, $this->elapsedMs($started), ActorType::User, $command->actorId);
        $this->audit->record($envelope, KeyOperation::WrapDek, AccessOutcome::Success, $this->elapsedMs($started), ActorType::User, $command->actorId);

        return ['envelope' => $envelope, 'handshake' => $handshake];
    }

    private function assertProviderMatchesMode(ConfigureByokOrHyokEnvelopeCommand $command): void
    {
        $platform = $command->provider === KmsProviderType::Platform;

        if ($command->mode->isCustomerManaged() === $platform) {
            throw new DataResidencyException($platform ? 'BYOK and HYOK need an external KMS/HSM provider.' : 'Only the Platform-Managed mode uses the platform key hierarchy.');
        }
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
