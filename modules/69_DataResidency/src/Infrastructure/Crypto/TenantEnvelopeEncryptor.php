<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Crypto;

use Modules\DataResidency\Domain\Exceptions\DataResidencyException;

/**
 * AES-256-GCM authenticated encryption with the tenant DEK: a random 96-bit nonce per record and the tenant, legal entity,
 * table and record id as additional authenticated data.
 *
 * Ciphertext format: `ffb1:<envelope id>:<base64(nonce . tag . ciphertext)>`.
 */
final class TenantEnvelopeEncryptor
{
    private const string PREFIX = 'ffb1';

    private const int NONCE_LENGTH = 12;

    private const int TAG_LENGTH = 16;

    public function __construct(private readonly EnvelopeKeyService $keys) {}

    public function encrypt(string $plaintext, EncryptionContext $context): string
    {
        $envelope = $this->keys->envelopeFor($context->tenantId, $context->legalEntityId);
        $dek = $this->keys->dek($envelope->id);

        try {
            $nonce = random_bytes(self::NONCE_LENGTH);
            $tag = '';
            $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $dek, OPENSSL_RAW_DATA, $nonce, $tag, $context->aad(), self::TAG_LENGTH);
        } finally {
            sodium_memzero($dek);
        }

        if ($cipher === false) {
            throw new DataResidencyException('Encryption failed.');
        }

        return self::PREFIX.':'.$envelope->id.':'.base64_encode($nonce.$tag.$cipher);
    }

    public function decrypt(string $payload, EncryptionContext $context): string
    {
        $parts = explode(':', $payload, 3);
        $raw = count($parts) === 3 && $parts[0] === self::PREFIX ? base64_decode($parts[2], true) : false;

        if ($raw === false || strlen($raw) < self::NONCE_LENGTH + self::TAG_LENGTH) {
            throw new DataResidencyException('Malformed ciphertext.');
        }

        $dek = $this->keys->dek($parts[1]);

        try {
            $plain = openssl_decrypt(
                substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH),
                'aes-256-gcm',
                $dek,
                OPENSSL_RAW_DATA,
                substr($raw, 0, self::NONCE_LENGTH),
                substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH),
                $context->aad(),
            );
        } finally {
            sodium_memzero($dek);
        }

        if ($plain === false) {
            throw new DataResidencyException('The ciphertext failed authentication: it is corrupt or bound to another tenant, entity, table or record.');
        }

        return $plain;
    }
}
