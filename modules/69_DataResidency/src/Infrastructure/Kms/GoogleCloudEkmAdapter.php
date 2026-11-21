<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * Google Cloud KMS with an External Key Manager key (europe-west6 Zurich, europe-west8 Milan). external_key_uri:
 * `projects/<p>/locations/<l>/keyRings/<r>/cryptoKeys/<k>`; credentials: `access_token`.
 */
final class GoogleCloudEkmAdapter extends RestKmsAdapter
{
    private const string BASE = 'https://cloudkms.googleapis.com/v1/';

    public function type(): KmsProviderType
    {
        return KmsProviderType::GoogleCloudEkm;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $data = $this->postJson(self::BASE.$envelope->external_key_uri.':encrypt', ['plaintext' => base64_encode($dek)], $this->headers($envelope));

        return $this->stringAt($data, 'ciphertext');
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $data = $this->postJson(self::BASE.$envelope->external_key_uri.':decrypt', ['ciphertext' => $wrappedDek], $this->headers($envelope));
        $plain = base64_decode($this->stringAt($data, 'plaintext'), true);

        if ($plain === false || strlen($plain) !== 32) {
            throw new KmsUnavailableException('Cloud KMS returned a malformed data key.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        $data = $this->postJson(self::BASE.$envelope->external_key_uri.'/cryptoKeyVersions', [], $this->headers($envelope));

        return basename($this->stringAt($data, 'name'));
    }

    /**
     * @return array<string, string>
     */
    private function headers(CustomerManagedKeyEnvelope $envelope): array
    {
        return ['Authorization' => 'Bearer '.(string) ($this->credentials($envelope)['access_token'] ?? '')];
    }
}
