<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Services;

/**
 * The country a URL's host belongs to: an explicit declaration first, then the configured host map
 * (data_residency.host_countries, exact host or domain suffix, filled from the GeoIP/DNS region verification of the
 * deployment), then a country-code top-level domain. A generic domain (.com, .io) with no map entry is unknown.
 */
final class EgressCountryResolver
{
    /** ccTLDs that differ from the ISO 3166 code. */
    private const array TLD_ALIASES = ['uk' => 'GB'];

    public function resolve(string $target, ?string $explicitCountry = null): ?string
    {
        if ($explicitCountry !== null && preg_match('/^[A-Za-z]{2}$/', $explicitCountry) === 1) {
            return strtoupper($explicitCountry);
        }

        $host = strtolower((string) (parse_url($target, PHP_URL_HOST) ?: $target));

        foreach ((array) config('data_residency.host_countries', []) as $suffix => $country) {
            $suffix = strtolower((string) $suffix);

            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return strtoupper((string) $country);
            }
        }

        $tld = substr((string) strrchr($host, '.'), 1);

        if (strlen($tld) === 2 && ctype_alpha($tld)) {
            return self::TLD_ALIASES[$tld] ?? strtoupper($tld);
        }

        return null;
    }
}
