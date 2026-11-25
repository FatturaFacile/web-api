<?php

declare(strict_types=1);

namespace Database\Seeders\Translations;

use InvalidArgumentException;

/**
 * API-served UI strings for Mod 233 (Issue #479): Van Selling (Tentata Vendita su Furgone)
 * & Offline Field Sales. Each entry is [it, en, de, fr].
 */
final class VanSellingFieldSalesFrontendTranslations
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function definitions(): array
    {
        return [
            'NAV.VAN_SELLING' => ['Tentata Vendita & Field Sales', 'Van Selling & Field Sales', 'Van Selling & Aussendienst', 'Vente Ambulante & Terrain'],
            'NAV.VAN_SELLING_VANS' => ['Furgoni & Sezionali', 'Vans & Sectionals', 'Fahrzeuge & Nummernkreise', 'Camions & Souches'],
            'NAV.VAN_SELLING_LOADS' => ['Carico Mattutino & DDT', 'Morning Load & DDT', 'Morgenbeladung & LS', 'Chargement & BL Global'],
            'NAV.VAN_SELLING_ROUTES' => ['Giro Visite & GS1-128', 'Route Visits & GS1-128', 'Touren & GS1-128', 'Tournées & GS1-128'],
            'NAV.VAN_SELLING_SYNC' => ['Sync Offline & Stampa BT', 'Offline Sync & BT Print', 'Offline-Sync & BT-Druck', 'Sync Offline & Imp. BT'],
            'NAV.VAN_SELLING_EOD' => ['Quadratura Fine Giro', 'End-of-Day Settlement', 'Tagesabschluss Furgone', 'Clôture Fin de Tournée'],
            'PAGE_TITLE.VAN_SELLING' => ['Tentata Vendita su Furgone & Field Sales Offline', 'Van Selling & Offline Field Sales', 'Van Selling & Offline-Aussendienst', 'Vente Ambulante & Terrain Offline'],
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
