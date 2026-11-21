<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * Azure Key Vault (Switzerland North, Italy North). external_key_uri: `https://<vault>.vault.azure.net/keys/<name>`;
 * credentials: `access_token` (an Entra ID token for https://vault.azure.net) and `region`.
 */
final class AzureKeyVaultAdapter extends RestKmsAdapter
{
    private const string API = '?api-version=7.4';

    private const string ALGORITHM = 'RSA-OAEP-256';

    public function type(): KmsProviderType
    {
        return KmsProviderType::AzureKeyVault;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $data = $this->postJson($this->keyUrl($envelope).'/wrapkey'.self::API, ['alg' => self::ALGORITHM, 'value' => $this->base64Url($dek)], $this->headers($envelope));

        return $this->stringAt($data, 'value');
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $data = $this->postJson($this->keyUrl($envelope).'/unwrapkey'.self::API, ['alg' => self::ALGORITHM, 'value' => $wrappedDek], $this->headers($envelope));
        $plain = base64_decode(strtr($this->stringAt($data, 'value'), '-_', '+/'), true);

        if ($plain === false || strlen($plain) !== 32) {
            throw new KmsUnavailableException('Key Vault returned a malformed data key.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        $data = $this->postJson(rtrim($envelope->external_key_uri, '/').'/rotate'.self::API, [], $this->headers($envelope));
        $kid = (string) (((array) ($data['key'] ?? []))['kid'] ?? '');

        return $kid === '' ? 'azure-'.now()->format('YmdHis') : basename($kid);
    }

    /** The unwrap targets the version that wrapped the DEK; without a version Azure uses the latest one. */
    private function keyUrl(CustomerManagedKeyEnvelope $envelope): string
    {
        $base = rtrim($envelope->external_key_uri, '/');

        return str_starts_with($envelope->current_key_version, 'azure-') || $envelope->current_key_version === '' ? $base : $base.'/'.$envelope->current_key_version;
    }

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private function headers(CustomerManagedKeyEnvelope $envelope): array
    {
        return ['Authorization' => 'Bearer '.(string) ($this->credentials($envelope)['access_token'] ?? '')];
    }
}
