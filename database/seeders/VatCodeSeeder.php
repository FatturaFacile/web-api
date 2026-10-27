<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\VatCode;
use App\Models\VatCodeTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VatCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vatCodes = [
            // Italian VAT codes
            [
                'code' => 'IT_22',
                'country_iso' => 'IT',
                'rate' => 22.00,
                'nature' => null,
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Ordinaria 22%', 'legal_description' => 'Aliquota IVA ordinaria ai sensi del DPR 633/72'],
                    'en' => ['name' => 'Standard VAT 22%', 'legal_description' => 'Standard Italian VAT rate under Presidential Decree 633/72'],
                    'de' => ['name' => 'Regel-MwSt. 22%', 'legal_description' => 'Standard-MwSt.-Satz nach italienischem Dekret 633/72'],
                    'fr' => ['name' => 'TVA Normale 22%', 'legal_description' => 'Taux de TVA standard selon le décret présidentiel 633/72'],
                ],
            ],
            [
                'code' => 'IT_10',
                'country_iso' => 'IT',
                'rate' => 10.00,
                'nature' => null,
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Ridotta 10%', 'legal_description' => 'Aliquota IVA ridotta per turismo, ristorazione ed edilizia'],
                    'en' => ['name' => 'Reduced VAT 10%', 'legal_description' => 'Reduced VAT rate for tourism, hospitality and building works'],
                    'de' => ['name' => 'Ermäßigte MwSt. 10%', 'legal_description' => 'Ermäßigter MwSt.-Satz für Tourismus und Bauarbeiten'],
                    'fr' => ['name' => 'TVA Réduite 10%', 'legal_description' => 'Taux de TVA réduit pour hôtellerie, restauration et travaux'],
                ],
            ],
            [
                'code' => 'IT_4',
                'country_iso' => 'IT',
                'rate' => 4.00,
                'nature' => null,
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Minima 4%', 'legal_description' => 'Aliquota IVA super-ridotta per beni di prima necessità e prima casa'],
                    'en' => ['name' => 'Super-reduced VAT 4%', 'legal_description' => 'Super-reduced VAT rate for basic food staples and first-home construction'],
                    'de' => ['name' => 'Stark ermäßigte MwSt. 4%', 'legal_description' => 'Stark ermäßigter MwSt.-Satz für Grundnahrungsmittel'],
                    'fr' => ['name' => 'TVA Super-réduite 4%', 'legal_description' => 'Taux super-réduit pour produits de première nécessité'],
                ],
            ],
            [
                'code' => 'IT_N3_1',
                'country_iso' => 'IT',
                'rate' => 0.00,
                'nature' => 'N3.1',
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'Non Imponibile Esportazioni Art. 8', 'legal_description' => 'Operazione non imponibile ai sensi dell\'Art. 8, comma 1, lett. a) DPR 633/72'],
                    'en' => ['name' => 'Non-taxable Direct Export Art. 8', 'legal_description' => 'Non-taxable export supply pursuant to Art. 8, par. 1, let. a) DPR 633/72'],
                    'de' => ['name' => 'Steuerfreie Ausfuhr Art. 8', 'legal_description' => 'Steuerfreie direkte Ausfuhr gemäß Art. 8 Abs. 1 Buchst. a DPR 633/72'],
                    'fr' => ['name' => 'Non imposable Exportation Art. 8', 'legal_description' => 'Opération non imposable en vertu de l\'art. 8, al. 1, let. a DPR 633/72'],
                ],
            ],
            [
                'code' => 'IT_N6_7',
                'country_iso' => 'IT',
                'rate' => 0.00,
                'nature' => 'N6.7',
                'is_split_payment' => false,
                'is_reverse_charge' => true,
                'translations' => [
                    'it' => ['name' => 'Reverse Charge Edilizia Art. 17', 'legal_description' => 'Inversione contabile - subappalto nel settore edile ai sensi dell\'Art. 17, comma 6 DPR 633/72'],
                    'en' => ['name' => 'Reverse Charge Construction Art. 17', 'legal_description' => 'Reverse charge on construction subcontracting under Art. 17, par. 6 DPR 633/72'],
                    'de' => ['name' => 'Steuerschuldumkehr Bauwesen Art. 17', 'legal_description' => 'Reverse Charge im Bausektor nach Art. 17 Abs. 6 DPR 633/72'],
                    'fr' => ['name' => 'Autoliquidation Bâtiment Art. 17', 'legal_description' => 'Autoliquidation dans le secteur du bâtiment selon l\'Art. 17, al. 6 DPR 633/72'],
                ],
            ],
            // Swiss VAT codes
            [
                'code' => 'CH_NORM_81',
                'country_iso' => 'CH',
                'rate' => 8.10,
                'nature' => '302',
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Svizzera Normale 8.1%', 'legal_description' => 'Aliquota normale secondo la Legge federale concernente l\'imposta sul valore aggiunto (LIVA)'],
                    'en' => ['name' => 'Swiss Standard VAT 8.1%', 'legal_description' => 'Standard VAT rate pursuant to Swiss Federal Act on Value Added Tax'],
                    'de' => ['name' => 'Schweizer Normalsatz 8.1%', 'legal_description' => 'Normalsatz gemäß schweizerischem Bundesgesetz über die Mehrwertsteuer (MWSTG)'],
                    'fr' => ['name' => 'TVA Suisse Taux Normal 8.1%', 'legal_description' => 'Taux normal selon la loi fédérale suisse régissant la taxe sur la valeur ajoutée (LTVA)'],
                ],
            ],
            [
                'code' => 'CH_RED_26',
                'country_iso' => 'CH',
                'rate' => 2.60,
                'nature' => '312',
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Svizzera Ridotta 2.6%', 'legal_description' => 'Aliquota ridotta per beni alimentari, medicinali, libri e giornali'],
                    'en' => ['name' => 'Swiss Reduced VAT 2.6%', 'legal_description' => 'Reduced VAT rate for foodstuffs, pharmaceuticals, books and newspapers'],
                    'de' => ['name' => 'Schweizer Reduzierter Satz 2.6%', 'legal_description' => 'Reduzierter Satz für Nahrungsmittel, Medikamente, Bücher und Zeitungen'],
                    'fr' => ['name' => 'TVA Suisse Taux Réduit 2.6%', 'legal_description' => 'Taux réduit pour denrées alimentaires, médicaments, livres et journaux'],
                ],
            ],
            [
                'code' => 'CH_SPEC_38',
                'country_iso' => 'CH',
                'rate' => 3.80,
                'nature' => '382',
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Svizzera Speciale Alloggio 3.8%', 'legal_description' => 'Aliquota speciale per il settore alberghiero e pernottamenti'],
                    'en' => ['name' => 'Swiss Accommodation VAT 3.8%', 'legal_description' => 'Special VAT rate for accommodation and lodging services'],
                    'de' => ['name' => 'Schweizer Sondersatz Beherbergung 3.8%', 'legal_description' => 'Sondersatz für Beherbergungsleistungen (Hotellerie)'],
                    'fr' => ['name' => 'TVA Suisse Taux Spécial Hébergement 3.8%', 'legal_description' => 'Taux spécial pour les prestations d\'hébergement hôtelier'],
                ],
            ],
            // German VAT codes
            [
                'code' => 'DE_19',
                'country_iso' => 'DE',
                'rate' => 19.00,
                'nature' => null,
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Germania Ordinaria 19%', 'legal_description' => 'Aliquota IVA ordinaria tedesca ai sensi del § 12 UStG'],
                    'en' => ['name' => 'German Standard VAT 19%', 'legal_description' => 'Standard German VAT rate pursuant to § 12 UStG'],
                    'de' => ['name' => 'Deutsche Regel-USt. 19%', 'legal_description' => 'Regelsteuersatz nach § 12 Abs. 1 UStG'],
                    'fr' => ['name' => 'TVA Allemagne Normale 19%', 'legal_description' => 'Taux standard de TVA allemande selon le § 12 UStG'],
                ],
            ],
            // French VAT codes
            [
                'code' => 'FR_20',
                'country_iso' => 'FR',
                'rate' => 20.00,
                'nature' => null,
                'is_split_payment' => false,
                'is_reverse_charge' => false,
                'translations' => [
                    'it' => ['name' => 'IVA Francia Ordinaria 20%', 'legal_description' => 'Aliquota IVA ordinaria francese ai sensi dell\'Art. 278 CGI'],
                    'en' => ['name' => 'French Standard VAT 20%', 'legal_description' => 'Standard French VAT rate pursuant to Art. 278 CGI'],
                    'de' => ['name' => 'Französische Standard-MwSt. 20%', 'legal_description' => 'Standard-MwSt.-Satz nach Art. 278 CGI'],
                    'fr' => ['name' => 'TVA France Normale 20%', 'legal_description' => 'Taux normal de TVA en vigueur selon l\'Art. 278 du Code général des impôts (CGI)'],
                ],
            ],
        ];

        DB::transaction(function () use ($vatCodes): void {
            foreach ($vatCodes as $codeData) {
                /** @var VatCode $vatCode */
                $vatCode = VatCode::updateOrCreate(
                    ['code' => $codeData['code']],
                    [
                        'country_iso' => $codeData['country_iso'],
                        'rate' => $codeData['rate'],
                        'nature' => $codeData['nature'],
                        'is_split_payment' => $codeData['is_split_payment'],
                        'is_reverse_charge' => $codeData['is_reverse_charge'],
                        'is_active' => true,
                    ]
                );

                foreach ($codeData['translations'] as $locale => $trans) {
                    VatCodeTranslation::updateOrCreate(
                        [
                            'vat_code_id' => $vatCode->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'legal_description' => $trans['legal_description'],
                        ]
                    );
                }
            }
        });
    }
}
