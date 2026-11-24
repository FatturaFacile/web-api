<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * HashiCorp Vault transit engine. external_key_uri: `<vault address>/v1/transit/keys/<name>`; credentials: `token`
 * (and optionally `namespace`).
 */
final class HashiCorpVaultTransitAdapter extends RestKmsAdapter
{
    public function type(): KmsProviderType
    {
        return KmsProviderType::HashicorpVault;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $data = $this->postJson($this->endpoint($envelope, 'encrypt'), ['plaintext' => base64_encode($dek)], $this->headers($envelope));

        return $this->stringAt((array) ($data['data'] ?? []), 'ciphertext');
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $data = $this->postJson($this->endpoint($envelope, 'decrypt'), ['ciphertext' => $wrappedDek], $this->headers($envelope));
        $plain = base64_decode($this->stringAt((array) ($data['data'] ?? []), 'plaintext'), true);

        if ($plain === false || strlen($plain) !== 32) {
            throw new KmsUnavailableException('Vault returned a malformed data key.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        $this->postJson(rtrim($envelope->external_key_uri, '/').'/rotate', [], $this->headers($envelope));

        try {
            $response = Http::timeout(10)->acceptJson()->withHeaders($this->headers($envelope))->get($envelope->external_key_uri);
        } catch (ConnectionException) {
            throw new KmsUnavailableException('Vault is unreachable.');
        }

        $latest = $this->decode($response)['data']['latest_version'] ?? null;

        return 'v'.(is_scalar($latest) ? (string) $latest : (string) now()->timestamp);
    }

    private function endpoint(CustomerManagedKeyEnvelope $envelope, string $operation): string
    {
        // <addr>/v1/transit/keys/<name> -> <addr>/v1/transit/<operation>/<name>
        return preg_replace('#/transit/keys/([^/]+)/?$#', '/transit/'.$operation.'/$1', $envelope->external_key_uri) ?? $envelope->external_key_uri;
    }

    /**
     * @return array<string, string>
     */
    private function headers(CustomerManagedKeyEnvelope $envelope): array
    {
        $credentials = $this->credentials($envelope);
        $headers = ['X-Vault-Token' => (string) ($credentials['token'] ?? '')];

        if (! empty($credentials['namespace'])) {
            $headers['X-Vault-Namespace'] = (string) $credentials['namespace'];
        }

        return $headers;
    }
}
