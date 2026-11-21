<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Throwable;

/**
 * Shared plumbing of the KMS adapters that speak a JSON REST API: the decrypted credentials of the envelope, the HTTP
 * call with a short timeout and the uniform mapping of every transport or authorisation failure to KmsUnavailableException.
 */
abstract class RestKmsAdapter implements KmsProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    protected function credentials(CustomerManagedKeyEnvelope $envelope): array
    {
        if ($envelope->kms_credentials_encrypted === null || $envelope->kms_credentials_encrypted === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($envelope->kms_credentials_encrypted), true);
        } catch (Throwable) {
            throw new KmsUnavailableException('The stored KMS credentials cannot be decrypted.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $body, array $headers = []): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->withHeaders($headers)->post($url, $body);
        } catch (ConnectionException) {
            throw new KmsUnavailableException($this->type()->value.' is unreachable.');
        }

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        if ($response->failed()) {
            throw new KmsUnavailableException(sprintf('%s refused the operation (HTTP %d).', $this->type()->value, $response->status()));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function stringAt(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new KmsUnavailableException($this->type()->value.' returned an unexpected response.');
        }

        return $value;
    }

    public function verifyCapabilities(CustomerManagedKeyEnvelope $envelope): array
    {
        $probe = random_bytes(32);

        try {
            $wrapped = $this->wrapKey($envelope, $probe);
            $unwrapOk = hash_equals($probe, $this->unwrapKey($envelope, $wrapped));

            return ['wrap' => true, 'unwrap' => $unwrapOk];
        } finally {
            sodium_memzero($probe);
        }
    }
}
