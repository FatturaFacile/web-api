<?php

declare(strict_types=1);

namespace Modules\DataResidency\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\DataResidency\Application\Commands\RotateOrRevokeByokCustomerMasterKeyCommand;
use Modules\DataResidency\Domain\Enums\AccessOutcome;
use Modules\DataResidency\Domain\Enums\ActorType;
use Modules\DataResidency\Domain\Enums\KeyLifecycleAction;
use Modules\DataResidency\Domain\Enums\KeyOperation;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Crypto\ShortLivedDekMemoryCache;
use Modules\DataResidency\Infrastructure\Kms\KmsProviderRegistry;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Services\ByokAuditLogger;

/**
 * ROTATE: a new KEK version is created in the customer KMS and the DEK is re-wrapped under it, so no row or file is
 * re-encrypted. SUSPEND / CRYPTO_SHRED (the kill-switch): the cached DEKs are purged on every node, the envelope is locked
 * and, for a shred, the wrapped DEK is overwritten with random noise and removed: the data is unreadable for good, even
 * for the infrastructure administrators.
 */
final class RotateOrRevokeByokCustomerMasterKeyHandler
{
    public function __construct(
        private readonly KmsProviderRegistry $providers,
        private readonly ShortLivedDekMemoryCache $cache,
        private readonly ByokAuditLogger $audit,
    ) {}

    public function handle(RotateOrRevokeByokCustomerMasterKeyCommand $command): CustomerManagedKeyEnvelope
    {
        $envelope = CustomerManagedKeyEnvelope::query()->withoutGlobalScopes()->where('tenant_id', $command->tenantId)->findOrFail($command->envelopeId);

        return $command->action === KeyLifecycleAction::Rotate ? $this->rotate($envelope, $command) : $this->killSwitch($envelope, $command);
    }

    private function rotate(CustomerManagedKeyEnvelope $envelope, RotateOrRevokeByokCustomerMasterKeyCommand $command): CustomerManagedKeyEnvelope
    {
        $envelope->ensureUsable();

        $adapter = $this->providers->for($envelope->provider_type);
        $started = hrtime(true);
        $dek = null;

        try {
            $dek = $adapter->unwrapKey($envelope, (string) $envelope->wrapped_dek_ciphertext);
            $newVersion = $adapter->createKeyVersion($envelope);
            $envelope->rotateKey($newVersion);
            $envelope->wrapped_dek_ciphertext = $adapter->wrapKey($envelope, $dek);
            $envelope->updated_by = $command->actorId;
        } catch (KmsUnavailableException $exception) {
            $this->audit->record($envelope, KeyOperation::RotateKek, AccessOutcome::KmsUnreachable, $this->elapsedMs($started), ActorType::User, $command->actorId, $command->sourceIp);

            throw $exception;
        } finally {
            if ($dek !== null) {
                sodium_memzero($dek);
            }
        }

        $envelope->save();
        $this->cache->invalidate($envelope->id);
        $this->audit->record($envelope, KeyOperation::RotateKek, AccessOutcome::Success, $this->elapsedMs($started), ActorType::User, $command->actorId, $command->sourceIp);

        return $envelope;
    }

    private function killSwitch(CustomerManagedKeyEnvelope $envelope, RotateOrRevokeByokCustomerMasterKeyCommand $command): CustomerManagedKeyEnvelope
    {
        $shred = $command->action === KeyLifecycleAction::CryptoShred;

        if ($command->reason === null || trim($command->reason) === '') {
            throw new DataResidencyException('A reason is required to trigger the kill-switch.');
        }

        if ($envelope->status->isLocked() && ! ($shred && $envelope->wrapped_dek_ciphertext !== null)) {
            throw new DataResidencyException('The key envelope is already locked.');
        }

        // The cached keys go first: from here on no node can decrypt, even if the database write below were to fail.
        $this->cache->invalidate($envelope->id);

        DB::transaction(function () use ($envelope, $command, $shred): void {
            $envelope->triggerKillSwitch($command->actorId, $command->reason ?? '', $shred);
            $envelope->updated_by = $command->actorId;

            if ($shred) {
                // Overwrite before removal so the ciphertext does not survive in the row's last committed value.
                $envelope->wrapped_dek_ciphertext = base64_encode(random_bytes(max(48, strlen((string) $envelope->wrapped_dek_ciphertext))));
                $envelope->save();
                $envelope->wrapped_dek_ciphertext = null;
            }

            $envelope->save();
        });

        $this->audit->record(
            $envelope,
            $shred ? KeyOperation::KillSwitchShred : KeyOperation::KillSwitchSuspend,
            AccessOutcome::Success,
            0,
            ActorType::Ciso,
            $command->actorId,
            $command->sourceIp,
        );

        return $envelope;
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
