<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * The platform's own key hierarchy for the Platform-Managed mode: the KEK of an envelope version is derived from the
 * application key, the DEK is wrapped with AES-256-GCM. Also the in-process KMS used on SQLite in local runs and tests.
 */
final class PlatformKmsAdapter implements KmsProviderInterface
{
    public function type(): KmsProviderType
    {
        return KmsProviderType::Platform;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($dek, 'aes-256-gcm', $this->kek($envelope), OPENSSL_RAW_DATA, $nonce, $tag, $envelope->id, 16);

        if ($cipher === false) {
            throw new KmsUnavailableException('The platform KMS could not wrap the key.');
        }

        return base64_encode($nonce.$tag.$cipher);
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $raw = base64_decode($wrappedDek, true);

        if ($raw === false || strlen($raw) < 28) {
            throw new KmsUnavailableException('The wrapped key is malformed.');
        }

        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->kek($envelope), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $envelope->id);

        if ($plain === false) {
            throw new KmsUnavailableException('The wrapped key failed authentication.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        return 'platform-'.bin2hex(random_bytes(4));
    }

    public function verifyCapabilities(CustomerManagedKeyEnvelope $envelope): array
    {
        return ['wrap' => true, 'unwrap' => true];
    }

    private function kek(CustomerManagedKeyEnvelope $envelope): string
    {
        return hash_hmac('sha256', 'dr-kek|'.$envelope->id.'|'.$envelope->current_key_version, (string) config('app.key'), true);
    }
}
