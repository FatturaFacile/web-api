<?php

declare(strict_types=1);

namespace App\Services\Reports\DailySummary;

use App\Models\Translation;
use App\Support\RegionalTranslations;
use Database\Seeders\Translations\ScheduledErpReportFrontendTranslations;

/**
 * Resolves localized strings for scheduled ERP daily reports across all 5 supported locales
 * (it-CH, de-CH, fr-CH, en, it-IT) from database translations with fallback to seeded dictionaries.
 */
final class ReportLocaleHelper
{
    /**
     * @var array<string, array<string, string>>
     */
    private array $dictionaryCache = [];

    /**
     * @param  array<string, scalar>  $params
     */
    public function trans(string $key, string $locale, array $params = [], ?string $tenantId = null): string
    {
        $normalized = Translation::normalizeLocale($locale);
        $dictionary = $this->loadDictionary($normalized, $tenantId);
        $template = $dictionary[$key] ?? $key;

        foreach ($params as $paramKey => $value) {
            $template = str_replace('{'.$paramKey.'}', (string) $value, $template);
        }

        return $template;
    }

    public function formatCurrency(float $amount, string $currency, string $locale): string
    {
        $normalized = Translation::normalizeLocale($locale);
        $decimalSep = $normalized === 'en' ? '.' : ',';
        $thousandsSep = match ($normalized) {
            'it-CH', 'de-CH', 'fr-CH' => "'",
            'en' => ',',
            default => '.',
        };

        return $currency.' '.number_format($amount, 2, $decimalSep, $thousandsSep);
    }

    /**
     * @return array<string, string>
     */
    private function loadDictionary(string $locale, ?string $tenantId): array
    {
        $cacheKey = ($tenantId ?? 'global').'|'.$locale;
        if (isset($this->dictionaryCache[$cacheKey])) {
            return $this->dictionaryCache[$cacheKey];
        }

        $base = Translation::baseLocale($locale);
        $fallback = ScheduledErpReportFrontendTranslations::all($locale);

        $dbRows = Translation::query()
            ->forTenant($tenantId)
            ->whereIn('locale', array_unique([$base, $locale]))
            ->where('key', 'like', 'SCHEDULED_REPORT.%')
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END ASC')
            ->get();

        $merged = $fallback;
        foreach ($dbRows as $row) {
            $merged[$row->key] = (string) $row->value;
        }

        if ($locale === 'de-CH') {
            $merged = RegionalTranslations::derive($locale, $merged);
        }

        return $this->dictionaryCache[$cacheKey] = $merged;
    }
}
