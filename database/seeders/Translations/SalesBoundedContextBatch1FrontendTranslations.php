<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * API-served UI strings for Epic [Dominio 04] SD#139 Batch 1 (Mod 033-042).
 * Each entry is [it, en, de, fr].
 */
final class SalesBoundedContextBatch1FrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.OMNICHANNEL_PIPELINE' => [
                'Pipeline CRM & Vendite Omnicanale',
                'Omnichannel CRM & Sales Pipeline',
                'Omnichannel-CRM & Vertriebspipeline',
                'Pipeline CRM & Ventes Omnicanales',
            ],
            'BREADCRUMB.OMNICHANNEL_PIPELINE' => [
                'Pipeline Omnicanale SD#139',
                'Omnichannel Pipeline SD#139',
                'Omnichannel-Pipeline SD#139',
                'Pipeline Omnicanal SD#139',
            ],
            'PAGE_TITLE.OMNICHANNEL_PIPELINE' => [
                'CRM, Vendite Omnicanale & Fatturazione (Mod 033–042)',
                'CRM, Omnichannel Sales & Invoicing (Mod 033–042)',
                'CRM, Omnichannel-Vertrieb & Fakturierung (Mod 033–042)',
                'CRM, Ventes Omnicanales & Facturation (Mod 033–042)',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(string $locale): array
    {
        $index = match ($locale) {
            'it' => 0,
            'en' => 1,
            'de' => 2,
            'fr' => 3,
            default => throw new InvalidArgumentException("Unsupported locale [{$locale}]."),
        };

        $resolved = [];
        foreach (self::definitions() as $key => $tuple) {
            $resolved[$key] = $tuple[$index];
        }

        return $resolved;
    }
}
