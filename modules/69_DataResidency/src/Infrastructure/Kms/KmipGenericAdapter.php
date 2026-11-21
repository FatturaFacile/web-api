<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * Any KMIP key manager reached through the customer's KMIP-over-HTTPS gateway (`kmip_endpoint`): Encrypt/Decrypt of the
 * unique identifier in external_key_uri. The binary TTLV transport is the gateway's job; credentials: `bearer_token`.
 */
final class KmipGenericAdapter extends RestKmsAdapter
{
    public function type(): KmsProviderType
    {
        return KmsProviderType::KmipGeneric;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $data = $this->postJson($this->endpoint($envelope).'/encrypt', ['uniqueIdentifier' => $envelope->external_key_uri, 'data' => base64_encode($dek)], $this->headers($envelope));

        return $this->stringAt($data, 'data');
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $data = $this->postJson($this->endpoint($envelope).'/decrypt', ['uniqueIdentifier' => $envelope->external_key_uri, 'data' => $wrappedDek], $this->headers($envelope));
        $plain = base64_decode($this->stringAt($data, 'data'), true);

        if ($plain === false || strlen($plain) !== 32) {
            throw new KmsUnavailableException('The KMIP server returned a malformed data key.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        return $this->stringAt($this->postJson($this->endpoint($envelope).'/rekey', ['uniqueIdentifier' => $envelope->external_key_uri], $this->headers($envelope)), 'uniqueIdentifier');
    }

    private function endpoint(CustomerManagedKeyEnvelope $envelope): string
    {
        if ($envelope->kmip_endpoint === null || $envelope->kmip_endpoint === '') {
            throw new KmsUnavailableException('No KMIP endpoint is configured.');
        }

        return rtrim($envelope->kmip_endpoint, '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(CustomerManagedKeyEnvelope $envelope): array
    {
        return ['Authorization' => 'Bearer '.(string) ($this->credentials($envelope)['bearer_token'] ?? '')];
    }
}
