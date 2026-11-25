<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use App\Models\Translation;

/**
 * Reads the texts the backend itself sends or prints (e-mails, SMS, the certificate of completion) from the translations
 * table, where the localization seeders keep every string of the module. The module has no language files of its own.
 *
 * A key that is not seeded comes back as the key: nothing is invented here, and a test that renders every notification
 * fails on it.
 */
final class SignatureTextResolver
{
    /** @var array<string, array<string, string>> */
    private array $cache = [];

    /**
     * @param  array<string, scalar|null>  $replace  `:name` placeholders
     */
    public function text(string $key, string $locale, array $replace = [], ?string $tenantId = null): string
    {
        $locale = Translation::normalizeLocale($locale);
        $text = $this->dictionary($locale, $tenantId)[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $text = str_replace(':'.$name, (string) $value, $text);
        }

        return $text;
    }

    /**
     * The language the text of a locale is written in: the regional variants share the dictionary of their language, the
     * rows saved under the variant itself win.
     *
     * @return array<string, string>
     */
    private function dictionary(string $locale, ?string $tenantId): array
    {
        $cacheKey = $locale.'|'.($tenantId ?? '-');

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $locales = array_values(array_unique([Translation::baseLocale($locale), $locale]));
        $rows = Translation::withoutGlobalScopes()
            ->whereIn('locale', $locales)
            ->where('key', 'like', 'signatures.%')
            ->where(static function ($query) use ($tenantId): void {
                $query->whereNull('tenant_id');

                if ($tenantId !== null) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->get(['locale', 'key', 'value', 'tenant_id']);

        // Weakest first, so the stronger row overwrites: base language, then regional variant; global, then the company.
        $sorted = $rows->sortBy(static fn (Translation $row): int => ($row->tenant_id !== null ? 2 : 0) + ($row->locale === Translation::baseLocale($row->locale) ? 0 : 1));
        $dictionary = [];

        foreach ($sorted as $row) {
            $dictionary[(string) $row->key] = (string) $row->value;
        }

        return $this->cache[$cacheKey] = $dictionary;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
