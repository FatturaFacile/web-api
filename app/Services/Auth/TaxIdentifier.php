<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Italian VAT numbers (11 digits) and Swiss UIDs (CHE + 9 digits), in the form used to compare them.
 */
final class TaxIdentifier
{
    /**
     * The canonical form of the identifier, or null when it is not valid for the country.
     */
    public static function normalize(string $value, string $country): ?string
    {
        $compact = (string) preg_replace('/[\s.\-]/', '', strtoupper($value));

        if ($country === 'IT') {
            $digits = str_starts_with($compact, 'IT') ? substr($compact, 2) : $compact;

            return preg_match('/^\d{11}$/', $digits) === 1 ? $digits : null;
        }

        if ($country === 'CH') {
            $uid = (string) preg_replace('/(MWST|TVA|IVA)$/', '', $compact);

            return preg_match('/^CHE\d{9}$/', $uid) === 1 ? $uid : null;
        }

        return null;
    }

    public static function formatUid(string $canonical): string
    {
        $digits = substr($canonical, 3);

        return sprintf('CHE-%s.%s.%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3));
    }

    /**
     * The active tenant with exactly this identifier, whichever way it was stored.
     */
    public static function findTenant(string $canonical): ?Tenant
    {
        $candidates = str_starts_with($canonical, 'CHE') ? [$canonical] : [$canonical, 'IT'.$canonical];

        return Tenant::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($candidates): void {
                foreach (['vat_number', 'uid_number'] as $column) {
                    foreach ($candidates as $candidate) {
                        $query->orWhereRaw(
                            "UPPER(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '.', ''), '-', '')) LIKE ?",
                            [$candidate.'%']
                        );
                    }
                }
            })
            ->orderBy('created_at')
            ->get()
            ->first(fn (Tenant $tenant): bool => self::matches($tenant, $canonical));
    }

    /**
     * Whether another tenant of the installation already holds this VAT number, compared without spaces, dots, dashes and
     * the IT prefix, so "IT 123" and "123" are the same number.
     */
    public static function vatNumberTaken(string $value, ?string $exceptTenantId = null): bool
    {
        $compact = self::compactVatNumber($value);

        if ($compact === '') {
            return false;
        }

        return Tenant::query()
            ->whereNotNull('vat_number')
            ->when($exceptTenantId !== null, static fn (Builder $query) => $query->whereKeyNot($exceptTenantId))
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(vat_number, ' ', ''), '.', ''), '-', '')) IN (?, ?)", [$compact, 'IT'.$compact])
            ->exists();
    }

    private static function compactVatNumber(string $value): string
    {
        $compact = (string) preg_replace('/[\s.\-]/', '', strtoupper($value));

        return str_starts_with($compact, 'IT') ? substr($compact, 2) : $compact;
    }

    /**
     * LIKE only narrows the search (a Swiss UID may carry a MWST suffix): the comparison happens on the canonical form.
     */
    private static function matches(Tenant $tenant, string $canonical): bool
    {
        $country = str_starts_with($canonical, 'CHE') ? 'CH' : 'IT';

        return self::normalize((string) $tenant->vat_number, $country) === $canonical
            || self::normalize((string) $tenant->uid_number, $country) === $canonical;
    }
}
