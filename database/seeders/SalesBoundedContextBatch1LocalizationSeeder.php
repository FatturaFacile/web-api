<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent 5-locale API seeder (it-CH, de-CH, fr-CH, en, it-IT) for Epic [Dominio 04] SD#139 Batch 1 (Mod 033-042).
 * Seeds all UI labels, workflow steps, statuses, lookup options, and CH/IT domain presets into the backend DB
 * so web-frontend requires zero static i18n text additions.
 */
final class SalesBoundedContextBatch1LocalizationSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    public const FIVE_LOCALES = ['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'];

    public function run(): void
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
                'SBC.TITLE' => 'CRM, Vendite Omnicanale & Fatturazione (CH/IT)',
                'SBC.MOD033.TITLE' => 'Clienti & Contatti 360° (UID / IDI & QR-IBAN)',
                'SBC.MOD034.TITLE' => 'Lead Management & Scoring Omnicanale',
                'SBC.MOD035.TITLE' => 'Pipeline Opportunità & Previsioni Vendita',
                'SBC.MOD036.TITLE' => 'Preventivi CPQ & Firma Elettronica ZertES',
                'SBC.MOD037.TITLE' => 'Ordini ATP/CTP & Accordi Quadro (Call-off)',
                'SBC.MOD038.TITLE' => 'Listini Avanzati, Sconti & Guardrail Margine',
                'SBC.MOD039.TITLE' => 'Abbonamenti Ricorrenti, Pro-Rata & Indice LIK',
                'SBC.MOD040.TITLE' => 'Bollettino di Consegna (Lieferschein) & Fatturazione',
                'SBC.MOD041.TITLE' => 'Provvigioni Agenti & Estratti Conto',
                'SBC.MOD042.TITLE' => 'Portale Clienti Self-Service (QR-Fattura & TWINT)',
                'SBC.PRESET.FISCAL_ID' => 'UID / IDI (CHE-xxx.xxx.xxx)',
                'SBC.PRESET.PAYMENT_RAIL' => 'TWINT / QR-Fattura Svizzera',
            ],
            'de-CH' => [
                'SBC.TITLE' => 'CRM, Omnichannel-Vertrieb & Fakturierung (CH/IT)',
                'SBC.MOD033.TITLE' => 'Kunden & Kontakte 360° (UID / QR-IBAN)',
                'SBC.MOD034.TITLE' => 'Lead-Management & Omnichannel-Scoring',
                'SBC.MOD035.TITLE' => 'Chancen-Pipeline & Umsatzprognose',
                'SBC.MOD036.TITLE' => 'CPQ-Offerten & ZertES-Signatur',
                'SBC.MOD037.TITLE' => 'ATP/CTP-Aufträge & Rahmenverträge (Abruf)',
                'SBC.MOD038.TITLE' => 'Preislisten, Rabattkaskaden & Margen-Guardrails',
                'SBC.MOD039.TITLE' => 'Wiederkehrende Abos, Pro-Rata & LIK-Index',
                'SBC.MOD040.TITLE' => 'Lieferschein & Sammelrechnung',
                'SBC.MOD041.TITLE' => 'Agentenprovisionen & Abrechnungen',
                'SBC.MOD042.TITLE' => 'Kundenportal Self-Service (QR-Rechnung & TWINT)',
                'SBC.PRESET.FISCAL_ID' => 'UID (CHE-xxx.xxx.xxx MWST)',
                'SBC.PRESET.PAYMENT_RAIL' => 'TWINT / Schweizer QR-Rechnung',
            ],
            'fr-CH' => [
                'SBC.TITLE' => 'CRM, Ventes Omnicanales & Facturation (CH/IT)',
                'SBC.MOD033.TITLE' => 'Clients & Contacts 360° (IDE / QR-IBAN)',
                'SBC.MOD034.TITLE' => 'Gestion des Leads & Scoring Omnicanal',
                'SBC.MOD035.TITLE' => 'Pipeline Opportunités & Prévisions',
                'SBC.MOD036.TITLE' => 'Devis CPQ & Signature Électronique ZertES',
                'SBC.MOD037.TITLE' => 'Commandes ATP/CTP & Accords-Cadres',
                'SBC.MOD038.TITLE' => 'Tarifs, Remises en Cascade & Garde-fous Marge',
                'SBC.MOD039.TITLE' => 'Abonnements Récurrents, Pro-Rata & Indice LIK',
                'SBC.MOD040.TITLE' => 'Bulletin de Livraison & Facturation Différée',
                'SBC.MOD041.TITLE' => 'Commissions Agents & Décomptes',
                'SBC.MOD042.TITLE' => 'Portail Client Self-Service (QR-Facture & TWINT)',
                'SBC.PRESET.FISCAL_ID' => 'IDE / UID (CHE-xxx.xxx.xxx TVA)',
                'SBC.PRESET.PAYMENT_RAIL' => 'TWINT / QR-Facture Suisse',
            ],
            'en' => [
                'SBC.TITLE' => 'CRM, Omnichannel Sales & Invoicing (CH/IT)',
                'SBC.MOD033.TITLE' => 'Customer & Contact 360° Management',
                'SBC.MOD034.TITLE' => 'Lead Management & Omnichannel Scoring',
                'SBC.MOD035.TITLE' => 'Opportunity Pipeline & Sales Forecasting',
                'SBC.MOD036.TITLE' => 'Interactive CPQ Quotes & eIDAS/ZertES Signature',
                'SBC.MOD037.TITLE' => 'ATP/CTP Sales Orders & Framework Agreements',
                'SBC.MOD038.TITLE' => 'Advanced Pricing, Discounts & Margin Guardrails',
                'SBC.MOD039.TITLE' => 'Recurring Billing, Pro-Rata & ISTAT/LIK Indexation',
                'SBC.MOD040.TITLE' => 'DDT / Lieferschein & Deferred Summary Invoicing',
                'SBC.MOD041.TITLE' => 'Sales Commissions, Splits & Enasarco Management',
                'SBC.MOD042.TITLE' => 'Customer Self-Service Portal (QR-Bill, Stripe, TWINT, SEPA)',
                'SBC.PRESET.FISCAL_ID' => 'Swiss UID / Italian P.IVA & SDI',
                'SBC.PRESET.PAYMENT_RAIL' => 'Stripe / TWINT / SEPA / Swiss QR-Bill',
            ],
            'it-IT' => [
                'SBC.TITLE' => 'CRM, Vendite Omnicanale & Fatturazione Elettronica SDI',
                'SBC.MOD033.TITLE' => 'Clienti & Contatti 360° (P.IVA, CF, SDI & PEC)',
                'SBC.MOD034.TITLE' => 'Lead Management & Scoring Omnicanale',
                'SBC.MOD035.TITLE' => 'Pipeline Opportunità & Previsioni Vendita',
                'SBC.MOD036.TITLE' => 'Preventivi CPQ & Firma Elettronica eIDAS',
                'SBC.MOD037.TITLE' => 'Ordini ATP/CTP & Accordi Quadro (Call-off)',
                'SBC.MOD038.TITLE' => 'Listini Avanzati, Sconti a Cascata & Guardrail Margine',
                'SBC.MOD039.TITLE' => 'Abbonamenti Ricorrenti, Pro-Rata & Rivalutazione ISTAT FOI',
                'SBC.MOD040.TITLE' => 'DDT, Fatturazione Differita Riepilogativa (TD24) & Note Credito',
                'SBC.MOD041.TITLE' => 'Provvigioni Agenti, Estratti Conto & Calcolo Enasarco / FIRR',
                'SBC.MOD042.TITLE' => 'Portale Clienti Self-Service (Stripe, SEPA & Estratto Conto)',
                'SBC.PRESET.FISCAL_ID' => 'Partita IVA, Codice Fiscale, Codice SDI & PEC',
                'SBC.PRESET.PAYMENT_RAIL' => 'Stripe / Bonifico & Addebito SEPA',
            ],
        ];
    }

    private function seedLookupPresets(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'sbc_pipeline_stage'],
            [
                'name' => 'Sales Bounded Context Pipeline Stages (SD#139)',
                'description' => '5-locale commercial pipeline stages for Mod 033-042',
                'is_system' => true,
            ]
        );

        $items = [
            'prospecting' => [
                'it-CH' => 'Prospezione',
                'de-CH' => 'Akquise',
                'fr-CH' => 'Prospection',
                'en' => 'Prospecting',
                'it-IT' => 'Prospezione',
            ],
            'qualification' => [
                'it-CH' => 'Qualificazione',
                'de-CH' => 'Qualifizierung',
                'fr-CH' => 'Qualification',
                'en' => 'Qualification',
                'it-IT' => 'Qualificazione',
            ],
            'proposal' => [
                'it-CH' => 'Offerta CPQ',
                'de-CH' => 'CPQ-Offerte',
                'fr-CH' => 'Offre CPQ',
                'en' => 'CPQ Proposal',
                'it-IT' => 'Preventivo CPQ',
            ],
            'negotiation' => [
                'it-CH' => 'Negoziazione & Firma',
                'de-CH' => 'Verhandlung & Signatur',
                'fr-CH' => 'Négociation & Signature',
                'en' => 'Negotiation & Signature',
                'it-IT' => 'Negoziazione & Firma',
            ],
            'closed_won' => [
                'it-CH' => 'Vinta',
                'de-CH' => 'Gewonnen',
                'fr-CH' => 'Gagnée',
                'en' => 'Closed Won',
                'it-IT' => 'Vinta',
            ],
        ];

        $order = 1;
        foreach ($items as $code => $labels) {
            $item = LookupItem::updateOrCreate(
                ['tenant_id' => null, 'category_id' => $category->id, 'code' => $code],
                ['sort_order' => $order++, 'is_active' => true, 'is_system' => true]
            );

            foreach ($labels as $locale => $label) {
                LookupItemTranslation::updateOrCreate(
                    ['tenant_id' => null, 'lookup_item_id' => $item->id, 'locale' => $locale],
                    ['label' => $label, 'short_label' => $code]
                );
            }
        }
    }
}
