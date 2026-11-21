<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\DataResidency\Domain\Enums\KeyEnvelopeStatus;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Events\ByokKeyRotatedEvent;
use Modules\DataResidency\Domain\Events\ByokKillSwitchActivatedEvent;
use Modules\DataResidency\Domain\Exceptions\TenantCryptoShreddedOrSuspendedException;

/**
 * The customer-managed envelope of a tenant or legal entity: the data-encryption key (DEK) is stored only wrapped by the
 * customer's key-encryption key (KEK) in an external KMS/HSM. The kill-switch makes every encrypted column and file
 * unreadable at once.
 *
 * @property string $id
 * @property string|null $legal_entity_id
 * @property KeyManagementMode $key_management_mode
 * @property KmsProviderType $provider_type
 * @property string $external_key_uri
 * @property string|null $kmip_endpoint
 * @property string|null $kms_credentials_encrypted
 * @property string $current_key_version
 * @property string|null $wrapped_dek_ciphertext
 * @property string $dek_wrap_algorithm
 * @property KeyEnvelopeStatus $status
 * @property int $dek_cache_ttl_seconds
 * @property int $rotation_interval_days
 * @property Carbon|null $last_rotated_at
 * @property Carbon|null $next_rotation_due_at
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 */
final class CustomerManagedKeyEnvelope extends BaseEnterpriseModel
{
    protected $table = 'core_byok_kms_configurations';

    protected $hidden = ['wrapped_dek_ciphertext', 'kms_credentials_encrypted'];

    protected static function booted(): void
    {
        // HYOK never caches a DEK; a locked envelope never caches either.
        self::saving(static function (self $envelope): void {
            if ($envelope->key_management_mode === KeyManagementMode::Hyok || $envelope->status->isLocked()) {
                $envelope->dek_cache_ttl_seconds = 0;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'key_management_mode' => KeyManagementMode::class,
            'provider_type' => KmsProviderType::class,
            'status' => KeyEnvelopeStatus::class,
            'dek_cache_ttl_seconds' => 'integer',
            'rotation_interval_days' => 'integer',
            'last_rotated_at' => 'datetime',
            'next_rotation_due_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * ROTATING -> ACTIVE on the new KEK version. The caller persists the envelope.
     */
    public function rotateKey(string $newKeyVersion): void
    {
        $this->ensureUsable();

        $old = $this->current_key_version;
        $this->status = KeyEnvelopeStatus::Rotating;

        $now = now();
        $this->current_key_version = $newKeyVersion;
        $this->last_rotated_at = $now;
        $this->next_rotation_due_at = $now->copy()->addDays($this->rotation_interval_days);
        $this->status = KeyEnvelopeStatus::Active;

        event(new ByokKeyRotatedEvent((string) $this->tenant_id, $this->id, $old, $newKeyVersion));
    }

    /**
     * Suspends the envelope, or revokes it for good (crypto-shredding). The caller persists the envelope and purges the
     * wrapped DEK material.
     */
    public function triggerKillSwitch(string $actorId, string $reason, bool $permanentCryptoShred = false): void
    {
        $this->status = $permanentCryptoShred ? KeyEnvelopeStatus::RevokedCryptoShredded : KeyEnvelopeStatus::Suspended;
        $this->dek_cache_ttl_seconds = 0;
        $this->revoked_at = now();
        $this->revocation_reason = $reason;

        event(new ByokKillSwitchActivatedEvent((string) $this->tenant_id, $this->id, $actorId, $reason, $permanentCryptoShred));
    }

    /**
     * @throws TenantCryptoShreddedOrSuspendedException
     */
    public function ensureUsable(): void
    {
        if ($this->status->isLocked()) {
            throw new TenantCryptoShreddedOrSuspendedException(sprintf('The key envelope is %s: encrypted data is locked.', $this->status->value));
        }
    }

    public function isRotationDue(): bool
    {
        return $this->next_rotation_due_at !== null && $this->next_rotation_due_at->isPast();
    }
}
