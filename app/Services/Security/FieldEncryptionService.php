<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

/**
 * AES-256-GCM envelope encryption of sensitive attributes with per-tenant, versioned data keys derived from a master
 * key (KEK), plus deterministic HMAC-SHA256 blind indexes for exact-match search over encrypted values.
 *
 * Ciphertext format: `ff1:<dek_version>:<base64(iv . tag . ciphertext)>`.
 */
final class FieldEncryptionService
{
    private const PREFIX = 'ff1';

    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    public function __construct(
        private readonly ?string $kek = null,
        private readonly ?int $currentVersion = null,
    ) {}

    public static function isCiphertext(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX.':');
    }

    public function currentVersion(): int
    {
        return max(1, $this->currentVersion ?? (int) config('field_encryption.current_version', 1));
    }

    public function encrypt(string $plaintext, FieldEncryptionContext $context, ?int $version = null): string
    {
        $version ??= $this->currentVersion();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->dek($context->tenantId, $version), OPENSSL_RAW_DATA, $iv, $tag, $context->aad(), self::TAG_LENGTH);

        if ($ciphertext === false) {
            throw new RuntimeException('Field encryption failed.');
        }

        return self::PREFIX.':'.$version.':'.base64_encode($iv.$tag.$ciphertext);
    }

    public function decrypt(string $payload, FieldEncryptionContext $context): string
    {
        [$version, $raw] = $this->parse($payload);

        if (strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('Malformed field ciphertext.');
        }

        $plaintext = openssl_decrypt(
            substr($raw, self::IV_LENGTH + self::TAG_LENGTH),
            self::CIPHER,
            $this->dek($context->tenantId, $version),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH),
            substr($raw, self::IV_LENGTH, self::TAG_LENGTH),
            $context->aad(),
        );

        if ($plaintext === false) {
            throw new RuntimeException('Field ciphertext failed authentication.');
        }

        return $plaintext;
    }

    /**
     * Re-encrypts a ciphertext under the current (or given) DEK version. A no-op when it is already there.
     */
    public function rotate(string $payload, FieldEncryptionContext $context, ?int $targetVersion = null): string
    {
        $targetVersion ??= $this->currentVersion();

        if ($this->versionOf($payload) === $targetVersion) {
            return $payload;
        }

        return $this->encrypt($this->decrypt($payload, $context), $context, $targetVersion);
    }

    public function versionOf(string $payload): int
    {
        return $this->parse($payload)[0];
    }

    /**
     * Deterministic blind index: equal inputs (after normalisation) give equal indexes within one tenant and field.
     */
    public function blindIndex(string $value, string $tenantId, string $fieldName): string
    {
        $normalised = mb_strtolower(preg_replace('/\s+/u', '', trim($value)) ?? '');

        return hash_hmac('sha256', $fieldName.'|'.$normalised, $this->derive($tenantId, 'blind-index'));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function parse(string $payload): array
    {
        $parts = explode(':', $payload, 3);

        if (count($parts) !== 3 || $parts[0] !== self::PREFIX || ! ctype_digit($parts[1])) {
            throw new RuntimeException('Malformed field ciphertext.');
        }

        $raw = base64_decode($parts[2], true);

        if ($raw === false) {
            throw new RuntimeException('Malformed field ciphertext.');
        }

        return [(int) $parts[1], $raw];
    }

    private function dek(string $tenantId, int $version): string
    {
        return $this->derive($tenantId, 'dek:v'.$version);
    }

    private function derive(string $tenantId, string $purpose): string
    {
        return hash_hkdf('sha256', $this->masterKey(), 32, 'fatturafacile|'.$purpose.'|'.$tenantId);
    }

    private function masterKey(): string
    {
        $encoded = $this->kek ?? config('field_encryption.kek');

        if ($encoded === null || $encoded === '') {
            // No dedicated KEK configured: derive a stable one from the application key (use a KMS-held KEK in production).
            $appKey = (string) config('app.key');

            if ($appKey === '') {
                throw new RuntimeException('FIELD_ENCRYPTION_KEK or APP_KEY must be configured.');
            }

            return hash_hkdf('sha256', $appKey, 32, 'fatturafacile|kek-fallback');
        }

        $key = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('FIELD_ENCRYPTION_KEK must be 32 random bytes, base64-encoded.');
        }

        return $key;
    }
}
