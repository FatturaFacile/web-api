<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentTerm;
use App\Models\PaymentTermTranslation;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentTermTranslationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::first();

        if (! $tenant) {
            $tenant = Tenant::updateOrCreate(
                ['vat_number' => 'IT12345678901'],
                [
                    'name' => 'Fattura Facile Italia S.r.l.',
                    'legal_name' => 'Fattura Facile Italia S.r.l.',
                    'fiscal_code' => '12345678901',
                    'country' => 'IT',
                    'default_currency' => 'EUR',
                ]
            );
        }

        $tenantId = $tenant->id;

        $termsData = [
            'IMMEDIATE' => [
                'days' => 0,
                'end_of_month' => false,
                'sdi_terms_code' => 'TP02',
                'translations' => [
                    'it' => ['name' => 'Pagamento Immediato', 'invoice_clause' => 'Pagamento a vista / rimessa immediata alla consegna.'],
                    'en' => ['name' => 'Immediate Payment', 'invoice_clause' => 'Payment due immediately upon receipt of goods or services.'],
                    'de' => ['name' => 'Sofortige Zahlung', 'invoice_clause' => 'Zahlung fällig sofort bei Erhalt der Ware oder Dienstleistung.'],
                    'fr' => ['name' => 'Paiement immédiat', 'invoice_clause' => 'Paiement exigible immédiatement à réception de la facture.'],
                ],
            ],
            'NET_30' => [
                'days' => 30,
                'end_of_month' => false,
                'sdi_terms_code' => 'TP02',
                'translations' => [
                    'it' => ['name' => 'Bonifico 30 giorni data fattura', 'invoice_clause' => 'Pagamento a 30 giorni netti data emissione documento.'],
                    'en' => ['name' => 'Net 30 Days', 'invoice_clause' => 'Payment due within 30 calendar days from invoice date.'],
                    'de' => ['name' => '30 Tage netto', 'invoice_clause' => 'Zahlbar innerhalb von 30 Tagen ab Rechnungsdatum ohne Abzug.'],
                    'fr' => ['name' => '30 jours net', 'invoice_clause' => 'Règlement à 30 jours nets date de facture sans escompte.'],
                ],
            ],
            'NET_60' => [
                'days' => 60,
                'end_of_month' => false,
                'sdi_terms_code' => 'TP02',
                'translations' => [
                    'it' => ['name' => 'Bonifico 60 giorni data fattura', 'invoice_clause' => 'Pagamento a 60 giorni netti data fattura.'],
                    'en' => ['name' => 'Net 60 Days', 'invoice_clause' => 'Payment due within 60 calendar days from invoice date.'],
                    'de' => ['name' => '60 Tage netto', 'invoice_clause' => 'Zahlbar innerhalb von 60 Tagen ab Rechnungsdatum rein netto.'],
                    'fr' => ['name' => '60 jours net', 'invoice_clause' => 'Règlement à 60 jours nets date de facture.'],
                ],
            ],
            '30_DF_EOM' => [
                'days' => 30,
                'end_of_month' => true,
                'sdi_terms_code' => 'TP02',
                'translations' => [
                    'it' => ['name' => '30 giorni fine mese', 'invoice_clause' => 'Pagamento a 30 giorni data fattura fine mese.'],
                    'en' => ['name' => '30 Days End of Month', 'invoice_clause' => 'Payment due 30 days from the end of the invoice month.'],
                    'de' => ['name' => '30 Tage Monatsende', 'invoice_clause' => 'Zahlbar 30 Tage nach Monatsende des Rechnungsdatums.'],
                    'fr' => ['name' => '30 jours fin de mois', 'invoice_clause' => 'Paiement à 30 jours fin de mois de facturation.'],
                ],
            ],
            '60_DF_EOM' => [
                'days' => 60,
                'end_of_month' => true,
                'sdi_terms_code' => 'TP02',
                'translations' => [
                    'it' => ['name' => '60 giorni fine mese', 'invoice_clause' => 'Pagamento a 60 giorni data fattura fine mese.'],
                    'en' => ['name' => '60 Days End of Month', 'invoice_clause' => 'Payment due 60 days from the end of the invoice month.'],
                    'de' => ['name' => '60 Tage Monatsende', 'invoice_clause' => 'Zahlbar 60 Tage nach Monatsende des Rechnungsdatums.'],
                    'fr' => ['name' => '60 jours fin de mois', 'invoice_clause' => 'Paiement à 60 jours fin de mois de facturation.'],
                ],
            ],
        ];

        DB::transaction(function () use ($termsData, $tenantId): void {
            foreach ($termsData as $code => $data) {
                /** @var PaymentTerm $term */
                $term = PaymentTerm::withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'code' => $code,
                    ],
                    [
                        'name' => $data['translations']['en']['name'],
                        'days' => $data['days'],
                        'end_of_month' => $data['end_of_month'],
                        'sdi_terms_code' => $data['sdi_terms_code'],
                    ]
                );

                foreach ($data['translations'] as $locale => $trans) {
                    PaymentTermTranslation::updateOrCreate(
                        [
                            'payment_term_id' => $term->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'invoice_clause' => $trans['invoice_clause'],
                        ]
                    );
                }
            }
        });
    }
}
