<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Services;

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;

/**
 * The UI locale of a request: user preference, then the HTTP Accept-Language header, then the default locale of the
 * active tenant/company, then English. Unsupported values are skipped, never an error.
 */
final class LocaleResolver
{
    public function resolve(?string $userPreference, ?string $acceptLanguage, ?string $tenantDefault, ?string $tenantCountry = null): SupportedLocale
    {
        $region = $tenantCountry !== null ? strtoupper($tenantCountry) : null;

        return $this->fromPreference($userPreference, $region)
            ?? $this->fromAcceptLanguage($acceptLanguage, $region)
            ?? $this->fromPreference($tenantDefault, $region)
            ?? SupportedLocale::En;
    }

    /**
     * The best supported locale of an Accept-Language header (q-values honoured, ties keep the header order).
     */
    public function fromAcceptLanguage(?string $header, ?string $region = null): ?SupportedLocale
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $part) {
            $pieces = explode(';', trim($part));
            $tag = trim($pieces[0]);
            $quality = 1.0;

            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            if ($tag !== '' && $tag !== '*' && $quality > 0) {
                $candidates[] = ['tag' => $tag, 'q' => $quality, 'position' => $position];
            }
        }

        usort($candidates, static fn (array $a, array $b): int => [$b['q'], $a['position']] <=> [$a['q'], $b['position']]);

        foreach ($candidates as $candidate) {
            $locale = $this->fromPreference($candidate['tag'], $region);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Maps a locale tag to a supported locale: exact match first, then the language (it-CH and it-IT share it; the
     * region of the tenant breaks the tie, Swiss variants otherwise).
     */
    public function fromPreference(?string $value, ?string $region = null): ?SupportedLocale
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $exact = SupportedLocale::tryFromLoose($value);

        if ($exact !== null) {
            return $exact;
        }

        $language = strtolower(explode('-', str_replace('_', '-', trim($value)))[0]);

        return match ($language) {
            'it' => $region === 'IT' ? SupportedLocale::ItIt : SupportedLocale::ItCh,
            'de' => SupportedLocale::DeCh,
            'fr' => SupportedLocale::FrCh,
            'en' => SupportedLocale::En,
            default => null,
        };
    }
}
