<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * RFC 5869 HKDF-SHA256 subkey derivation from the master application key (`app.key`).
 * Ensures domain separation between blind indexes, backup signatures, and encryption keys.
 */
final class KeyDerivation
{
    public static function derive(string $context, int $length = 32): string
    {
        $masterKey = (string) config('app.key', '');
        if ($masterKey === '') {
            $masterKey = 'fallback-empty-app-key';
        }

        return hash_hkdf('sha256', $masterKey, max(16, $length), $context);
    }

    public static function rawAppKey(): string
    {
        return (string) config('app.key', '');
    }
}
