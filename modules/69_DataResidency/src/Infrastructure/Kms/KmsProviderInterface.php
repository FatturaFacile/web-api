<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * The customer's external key-management system: it wraps and unwraps the 256-bit data-encryption key with the
 * key-encryption key that never leaves the KMS/HSM.
 */
interface KmsProviderInterface
{
    public function type(): KmsProviderType;

    /**
     * Wraps a raw DEK; the result is an opaque string the KMS alone can unwrap.
     *
     * @throws KmsUnavailableException
     */
    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string;

    /**
     * @throws KmsUnavailableException
     */
    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string;

    /**
     * Creates a new version of the KEK and returns its identifier. The previous version stays available for unwrapping.
     *
     * @throws KmsUnavailableException
     */
    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string;

    /**
     * Probes the key with a throw-away DEK: whether the credentials may wrap and unwrap.
     *
     * @return array{wrap: bool, unwrap: bool}
     */
    public function verifyCapabilities(CustomerManagedKeyEnvelope $envelope): array;
}
