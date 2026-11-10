<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

use App\Support\Security\KeyDerivation;

/**
 * A keyed hash of an encrypted value, so that it can be compared in a query without being readable. It is bound to the
 * tenant: the same value hashes differently in two companies.
 */
final class BlindIndex
{
    public const string KEY_CONTEXT = 'associations.blind_index.v1';

    public static function taxCode(?string $taxCode, string $tenantId): ?string
    {
        $normalized = strtoupper(trim((string) $taxCode));

        return $normalized === '' ? null : self::hash("tax-code|{$tenantId}|{$normalized}");
    }

    public static function badge(string $uid, string $tenantId): string
    {
        return self::hash('badge|'.$tenantId.'|'.strtoupper(trim($uid)));
    }

    /**
     * Candidate hashes (HKDF-derived primary + legacy raw `app.key` fallback) for backward-compatible lookups.
     *
     * @return list<string>
     */
    public static function taxCodeCandidates(?string $taxCode, string $tenantId): array
    {
        $normalized = strtoupper(trim((string) $taxCode));
        if ($normalized === '') {
            return [];
        }

        $payload = "tax-code|{$tenantId}|{$normalized}";

        return array_values(array_unique([
            self::hash($payload),
            self::legacyHash($payload),
        ]));
    }

    public static function verifyTaxCode(?string $taxCode, string $tenantId, string $storedHash): bool
    {
        foreach (self::taxCodeCandidates($taxCode, $tenantId) as $candidate) {
            if (hash_equals($candidate, $storedHash)) {
                return true;
            }
        }

        return false;
    }

    public static function verifyBadge(string $uid, string $tenantId, string $storedHash): bool
    {
        $payload = 'badge|'.$tenantId.'|'.strtoupper(trim($uid));

        return hash_equals(self::hash($payload), $storedHash)
            || hash_equals(self::legacyHash($payload), $storedHash);
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, KeyDerivation::derive(self::KEY_CONTEXT));
    }

    private static function legacyHash(string $value): string
    {
        return hash_hmac('sha256', $value, KeyDerivation::rawAppKey());
    }
}
