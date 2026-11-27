<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent 5-locale API seeder (it-CH, de-CH, fr-CH, en, it-IT) for Epic [Dominio 04] SD#139 Batch 2 (Mod 155-358).
 */
final class SalesBoundedContextBatch2LocalizationSeeder extends GlobalDataSeeder
{
    /**
     * @var list<string>
     */
    public const FIVE_LOCALES = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    protected function seedGlobalData(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            $translations = self::fiveLocaleDictionaries();

            foreach ($translations as $locale => $entries) {
                $keys = array_keys($entries);
                foreach (array_chunk($keys, 500) as $chunk) {
                    DB::table('translations')
                        ->whereNull('tenant_id')
                        ->where('locale', $locale)
                        ->whereIn('key', $chunk)
                        ->delete();
                }

                $rows = [];
                foreach ($entries as $key => $value) {
                    $rows[] = [
                        'tenant_id' => null,
                        'locale' => $locale,
                        'key' => $key,
                        'value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('translations')->insert($chunk);
                }
            }

            $this->seedLookupPresets();
        });

        foreach (self::FIVE_LOCALES as $locale) {
            Cache::forget("translations_global_{$locale}");
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function fiveLocaleDictionaries(): array
    {
        return [
            'it-CH' => [
                'SBC.MOD155.TITLE' => 'Marketing Automation, DEM & Segmentazione RFM (nLPD / LCSl)',
                'SBC.MOD169.TITLE' => 'Gare Pubbliche & Private (simap.ch LAPub & ANAC MePA)',
                'SBC.MOD230.TITLE' => 'Configuratore CPQ Tecnico-Commerciale & Super-BOM',
                'SBC.MOD231.TITLE' => 'Contratti Quadro GDO, Premi PFA & Ratei Mensili',
                'SBC.MOD232.TITLE' => 'Resi Avanzati, Cross-Shipping, B-Stock & Art. 26',
                'SBC.MOD233.TITLE' => 'Tentata Vendita, Magazzino Viaggiante & Quadratura',
                'SBC.MOD244.TITLE' => 'Portale Franchising, Royalty & Benchmarking Rete',
                'SBC.MOD245.TITLE' => 'Sondaggi Post-Consegna NPS / CSAT / CES & ISO 9001',
                'SBC.MOD358.TITLE' => 'Convenzioni Aziendali, CRAL & Welfare (Reka / Art. 51)',
            ],
            'de-CH' => [
                'SBC.MOD155.TITLE' => 'Marketing-Automation, DEM & RFM-Segmentierung (DSG / UWG)',
                'SBC.MOD169.TITLE' => 'Öffentliche & private Ausschreibungen (simap.ch LAPub / ANAC)',
                'SBC.MOD230.TITLE' => 'Technischer CPQ-Konfigurator & Super-BOM-Auflösung',
                'SBC.MOD231.TITLE' => 'GDO-Rahmenverträge, Jahresboni (PFA) & Abgrenzungen',
                'SBC.MOD232.TITLE' => 'Retourenmanagement, Cross-Shipping, B-Ware & Art. 26',
                'SBC.MOD233.TITLE' => 'Van Selling, fahrendes Lager & Tourenabschluss',
                'SBC.MOD244.TITLE' => 'Franchising-Portal, Royalties & Store-Benchmarking',
                'SBC.MOD245.TITLE' => 'Post-Delivery-Umfragen NPS / CSAT / CES & ISO 9001',
                'SBC.MOD358.TITLE' => 'Firmenkonventionen, CRAL & Reka / Art. 51 Split-Billing',
            ],
            'fr-CH' => [
                'SBC.MOD155.TITLE' => 'Marketing Automation, DEM & Segmentation RFM (LPD / LCD)',
                'SBC.MOD169.TITLE' => 'Appels d\'Offres Publics & Privés (simap.ch LAPub / ANAC)',
                'SBC.MOD230.TITLE' => 'Configurateur CPQ Technique & Nomenclature Super-BOM',
                'SBC.MOD231.TITLE' => 'Contrats-Cadres GDO, Ristournes PFA & Provisions Mensuelles',
                'SBC.MOD232.TITLE' => 'Retours Avancés, Cross-Shipping, Stock B & Art. 26',
                'SBC.MOD233.TITLE' => 'Vente Embarquée, Stock Mobile & Réconciliation Tournée',
                'SBC.MOD244.TITLE' => 'Portail Franchising, Redevances & Benchmarking Réseau',
                'SBC.MOD245.TITLE' => 'Enquêtes Post-Livraison NPS / CSAT / CES & ISO 9001',
                'SBC.MOD358.TITLE' => 'Conventions Entreprises, CRAL & Reka / Art. 51 TUIR',
            ],
            'en' => [
                'SBC.MOD155.TITLE' => 'Marketing Automation, DEM Newsletter & RFM Segmentation',
                'SBC.MOD169.TITLE' => 'Public & Private Tenders (simap.ch LAPub & ANAC MePA)',
                'SBC.MOD230.TITLE' => 'Technical-Commercial CPQ Configurator & Super-BOM',
                'SBC.MOD231.TITLE' => 'GDO Framework Contracts, Tiered PFA Rebates & Accruals',
                'SBC.MOD232.TITLE' => 'Reverse Logistics, Cross-Shipping, B-Stock & Art. 26 Credit Notes',
                'SBC.MOD233.TITLE' => 'Van Selling, Traveling Warehouse & Route Reconciliation',
                'SBC.MOD244.TITLE' => 'Franchising Portal, Sell-Out Royalties & Benchmarking',
                'SBC.MOD245.TITLE' => 'Post-Ticket / Post-Delivery Surveys (NPS / CSAT / CES / ISO 9001)',
                'SBC.MOD358.TITLE' => 'Corporate Conventions, CRAL & B2B Welfare Split Billing',
            ],
            'it-IT' => [
                'SBC.MOD155.TITLE' => 'Marketing Automation, DEM & Segmentazione RFM (GDPR / Esclusione Insoluti)',
                'SBC.MOD169.TITLE' => 'Gare Pubbliche & Private (ANAC MePA D.Lgs. 36/2023, eDGUE, FVOE, CIG/CUP)',
                'SBC.MOD230.TITLE' => 'Configuratore CPQ Tecnico-Commerciale & Esplosione Super-BOM',
                'SBC.MOD231.TITLE' => 'Contratti Quadro GDO, Premi Fine Anno (PFA) & Ratei Mensili',
                'SBC.MOD232.TITLE' => 'Resi Avanzati, Cross-Shipping, B-Stock & Note Credito Art. 26 DPR 633/72',
                'SBC.MOD233.TITLE' => 'Tentata Vendita, Magazzino Viaggiante, Sezionali & Quadratura Fine Giro',
                'SBC.MOD244.TITLE' => 'Portale Franchising, Calcolo Royalty & Listini Centralizzati',
                'SBC.MOD245.TITLE' => 'Sondaggi Post-Ticket / Consegna (NPS / CSAT / CES) & Report ISO 9001',
                'SBC.MOD358.TITLE' => 'Convenzioni Aziendali, CRAL & Welfare Art. 51 TUIR / Reka',
            ],
        ];
    }

    private function seedLookupPresets(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'sbc_tender_framework'],
            [
                'name' => 'Public & Private Tender Frameworks (SD#139 Mod 169)',
                'description' => '5-locale tender procurement frameworks for CH/IT',
                'is_system' => true,
            ]
        );

        $items = [
            'simap_ch_lapub' => [
                'it-CH' => 'simap.ch (LAPub Svizzera)',
                'de-CH' => 'simap.ch (BöB / LAPub Schweiz)',
                'fr-CH' => 'simap.ch (LMP / LAPub Suisse)',
                'en' => 'simap.ch (Swiss LAPub)',
                'it-IT' => 'simap.ch (Appalti Pubblici CH)',
            ],
            'anac_mepa_dlgs36_2023' => [
                'it-CH' => 'ANAC MePA (D.Lgs. 36/2023 IT)',
                'de-CH' => 'ANAC MePA (D.Lgs. 36/2023 IT)',
                'fr-CH' => 'ANAC MePA (D.Lgs. 36/2023 IT)',
                'en' => 'ANAC MePA (D.Lgs. 36/2023 IT)',
                'it-IT' => 'ANAC MePA (D.Lgs. 36/2023)',
            ],
            'private_rfp' => [
                'it-CH' => 'Gara Privata / RFP',
                'de-CH' => 'Private Ausschreibung / RFP',
                'fr-CH' => 'Appel d\'Offres Privé / RFP',
                'en' => 'Private Enterprise RFP',
                'it-IT' => 'Gara Privata / RFP',
            ],
        ];

        $order = 1;
        foreach ($items as $code => $labels) {
            $item = LookupItem::updateOrCreate(
                ['category_id' => $category->id, 'code' => $code],
                ['sort_order' => $order++, 'is_active' => true, 'is_system' => true]
            );

            foreach ($labels as $locale => $label) {
                LookupItemTranslation::updateOrCreate(
                    ['lookup_item_id' => $item->id, 'locale' => $locale],
                    ['label' => $label, 'short_label' => $code]
                );
            }
        }
    }
}
