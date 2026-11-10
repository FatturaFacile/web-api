<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/**
 * RFC 5869 HKDF-SHA256 subkey derivation from the master application key (`app.key`).
 * Ensures domain separation between blind indexes, backup signatures, and encryption keys.
 */
final class KeyDerivation
{
    public static function derive(string $context, int $length = 32): string
    {
        $masterKey = self::rawAppKey();

        return hash_hkdf('sha256', $masterKey, max(16, $length), $context);
    }

    public static function rawAppKey(): string
    {
        $masterKey = trim((string) config('app.key', ''));
        if ($masterKey === '') {
            throw new RuntimeException('Application encryption key (app.key / APP_KEY) is missing; refusing to derive cryptographic subkeys.');
        }

        return $masterKey;
    }
}
