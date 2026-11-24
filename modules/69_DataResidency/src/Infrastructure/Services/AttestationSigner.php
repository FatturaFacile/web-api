<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

/**
 * Ed25519 signature of the canonical attestation payload with the platform attestation key
 * (data_residency.attestation_signing_key: base64 of the 32-byte seed). Without a configured key a seed is derived from
 * the application key, which is acceptable for local runs only: production must set a dedicated key.
 */
final class AttestationSigner
{
    /**
     * @return array{signature: string, fingerprint: string, public_key: string}
     */
    public function sign(string $canonicalPayload): array
    {
        $keypair = sodium_crypto_sign_seed_keypair($this->seed());
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $signature = sodium_crypto_sign_detached($canonicalPayload, $secret);
        sodium_memzero($secret);
        sodium_memzero($keypair);

        return ['signature' => base64_encode($signature), 'fingerprint' => self::fingerprint($public), 'public_key' => base64_encode($public)];
    }

    /** The public half of the platform attestation key, base64. */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($this->seed())));
    }

    public function verify(string $canonicalPayload, string $signatureB64, string $publicKeyB64): bool
    {
        $signature = base64_decode($signatureB64, true);
        $public = base64_decode($publicKeyB64, true);

        if ($signature === false || $public === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $canonicalPayload, $public);
    }

    public static function fingerprint(string $publicKey): string
    {
        return 'ed25519:'.hash('sha256', $publicKey);
    }

    /**
     * Canonical JSON: keys sorted recursively, no insignificant whitespace, unescaped slashes and unicode.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function canonicalize(array $payload): string
    {
        return (string) json_encode(self::sortKeys($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function seed(): string
    {
        $configured = config('data_residency.attestation_signing_key');
        $decoded = is_string($configured) && $configured !== '' ? base64_decode($configured, true) : false;

        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            return $decoded;
        }

        return hash('sha256', 'data-residency-attestation|'.(string) config('app.key'), true);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
