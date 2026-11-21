<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Crypto;

use Modules\DataResidency\Domain\Enums\AccessOutcome;
use Modules\DataResidency\Domain\Enums\KeyOperation;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Domain\Exceptions\TenantCryptoShreddedOrSuspendedException;
use Modules\DataResidency\Infrastructure\Kms\KmsProviderRegistry;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;

/**
 * Hands out the plaintext DEK of an envelope: from the short-lived cache when its TTL allows, otherwise by unwrapping it in
 * the customer KMS/HSM. The envelope is always re-read from the database, so a kill-switch triggered on another node is
 * honoured on the very next call.
 */
final class EnvelopeKeyService
{
    public function __construct(
        private readonly KmsProviderRegistry $providers,
        private readonly ShortLivedDekMemoryCache $cache,
        private readonly ByokAuditLogger $audit,
    ) {}

    /**
     * The envelope that protects the data of a legal entity, falling back to the tenant default.
     */
    public function envelopeFor(string $tenantId, ?string $legalEntityId): CustomerManagedKeyEnvelope
    {
        $base = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $tenantId);

        $envelope = ($legalEntityId !== null ? (clone $base)->where('legal_entity_id', $legalEntityId)->first() : null)
            ?? (clone $base)->whereNull('legal_entity_id')->first();

        return $envelope ?? throw new DataResidencyException('No key envelope is configured for this tenant.');
    }

    /**
     * @throws TenantCryptoShreddedOrSuspendedException
     * @throws KmsUnavailableException
     */
    public function dek(string $envelopeId): string
    {
        $envelope = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->findOrFail($envelopeId);

        if ($envelope->status->isLocked()) {
            $this->cache->invalidate($envelope->id);
            $this->audit->record($envelope, KeyOperation::AccessDeniedRevoked, AccessOutcome::Denied);
            $envelope->ensureUsable();
        }

        $cached = $this->cache->get($envelope->id);

        if ($cached !== null) {
            $this->audit->record($envelope, KeyOperation::CacheHit, AccessOutcome::Success);

            return $cached;
        }

        $started = hrtime(true);

        try {
            $dek = $this->providers->for($envelope->provider_type)->unwrapKey($envelope, (string) $envelope->wrapped_dek_ciphertext);
        } catch (KmsUnavailableException $exception) {
            $this->audit->record($envelope, KeyOperation::UnwrapDek, AccessOutcome::KmsUnreachable, $this->elapsedMs($started));

            throw $exception;
        }

        $this->audit->record($envelope, KeyOperation::UnwrapDek, AccessOutcome::Success, $this->elapsedMs($started));
        $this->cache->put($envelope->id, $dek, $envelope->dek_cache_ttl_seconds);

        return $dek;
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
