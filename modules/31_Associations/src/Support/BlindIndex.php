<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

/**
 * A keyed hash of an encrypted value, so that it can be compared in a query without being readable. It is bound to the
 * tenant: the same value hashes differently in two companies.
 */
final class BlindIndex
{
    public static function taxCode(?string $taxCode, string $tenantId): ?string
    {
        $normalized = strtoupper(trim((string) $taxCode));

        return $normalized === '' ? null : self::hash("tax-code|{$tenantId}|{$normalized}");
    }

    public static function badge(string $uid, string $tenantId): string
    {
        return self::hash('badge|'.$tenantId.'|'.strtoupper(trim($uid)));
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
