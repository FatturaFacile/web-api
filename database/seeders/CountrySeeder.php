<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\CountryTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            [
                'iso_code_2' => 'IT',
                'iso_code_3' => 'ITA',
                'numeric_code' => '380',
                'phone_prefix' => '+39',
                'is_eu_member' => true,
                'is_sepa_member' => true,
                'currency_code' => 'EUR',
                'translations' => [
                    'it' => ['name' => 'Italia', 'nationality' => 'Italiana'],
                    'en' => ['name' => 'Italy', 'nationality' => 'Italian'],
                    'de' => ['name' => 'Italien', 'nationality' => 'Italienisch'],
                    'fr' => ['name' => 'Italie', 'nationality' => 'Italienne'],
                ],
            ],
            [
                'iso_code_2' => 'CH',
                'iso_code_3' => 'CHE',
                'numeric_code' => '756',
                'phone_prefix' => '+41',
                'is_eu_member' => false,
                'is_sepa_member' => true,
                'currency_code' => 'CHF',
                'translations' => [
                    'it' => ['name' => 'Svizzera', 'nationality' => 'Svizzera'],
                    'en' => ['name' => 'Switzerland', 'nationality' => 'Swiss'],
                    'de' => ['name' => 'Schweiz', 'nationality' => 'Schweizerisch'],
                    'fr' => ['name' => 'Suisse', 'nationality' => 'Suisse'],
                ],
            ],
            [
                'iso_code_2' => 'DE',
                'iso_code_3' => 'DEU',
                'numeric_code' => '276',
                'phone_prefix' => '+49',
                'is_eu_member' => true,
                'is_sepa_member' => true,
                'currency_code' => 'EUR',
                'translations' => [
                    'it' => ['name' => 'Germania', 'nationality' => 'Tedesca'],
                    'en' => ['name' => 'Germany', 'nationality' => 'German'],
                    'de' => ['name' => 'Deutschland', 'nationality' => 'Deutsch'],
                    'fr' => ['name' => 'Allemagne', 'nationality' => 'Allemande'],
                ],
            ],
            [
                'iso_code_2' => 'FR',
                'iso_code_3' => 'FRA',
                'numeric_code' => '250',
                'phone_prefix' => '+33',
                'is_eu_member' => true,
                'is_sepa_member' => true,
                'currency_code' => 'EUR',
                'translations' => [
                    'it' => ['name' => 'Francia', 'nationality' => 'Francese'],
                    'en' => ['name' => 'France', 'nationality' => 'French'],
                    'de' => ['name' => 'Frankreich', 'nationality' => 'Französisch'],
                    'fr' => ['name' => 'France', 'nationality' => 'Française'],
                ],
            ],
            [
                'iso_code_2' => 'AT',
                'iso_code_3' => 'AUT',
                'numeric_code' => '040',
                'phone_prefix' => '+43',
                'is_eu_member' => true,
                'is_sepa_member' => true,
                'currency_code' => 'EUR',
                'translations' => [
                    'it' => ['name' => 'Austria', 'nationality' => 'Austriaca'],
                    'en' => ['name' => 'Austria', 'nationality' => 'Austrian'],
                    'de' => ['name' => 'Österreich', 'nationality' => 'Österreichisch'],
                    'fr' => ['name' => 'Autriche', 'nationality' => 'Autrichienne'],
                ],
            ],
            [
                'iso_code_2' => 'LI',
                'iso_code_3' => 'LIE',
                'numeric_code' => '438',
                'phone_prefix' => '+423',
                'is_eu_member' => false,
                'is_sepa_member' => true,
                'currency_code' => 'CHF',
                'translations' => [
                    'it' => ['name' => 'Liechtenstein', 'nationality' => 'Liechtensteinese'],
                    'en' => ['name' => 'Liechtenstein', 'nationality' => 'Liechtensteiner'],
                    'de' => ['name' => 'Liechtenstein', 'nationality' => 'Liechtensteinisch'],
                    'fr' => ['name' => 'Liechtenstein', 'nationality' => 'Liechtensteinoise'],
                ],
            ],
            [
                'iso_code_2' => 'ES',
                'iso_code_3' => 'ESP',
                'numeric_code' => '724',
                'phone_prefix' => '+34',
                'is_eu_member' => true,
                'is_sepa_member' => true,
                'currency_code' => 'EUR',
                'translations' => [
                    'it' => ['name' => 'Spagna', 'nationality' => 'Spagnola'],
                    'en' => ['name' => 'Spain', 'nationality' => 'Spanish'],
                    'de' => ['name' => 'Spanien', 'nationality' => 'Spanisch'],
                    'fr' => ['name' => 'Espagne', 'nationality' => 'Espagnole'],
                ],
            ],
            [
                'iso_code_2' => 'GB',
                'iso_code_3' => 'GBR',
                'numeric_code' => '826',
                'phone_prefix' => '+44',
                'is_eu_member' => false,
                'is_sepa_member' => true,
                'currency_code' => 'GBP',
                'translations' => [
                    'it' => ['name' => 'Regno Unito', 'nationality' => 'Britannica'],
                    'en' => ['name' => 'United Kingdom', 'nationality' => 'British'],
                    'de' => ['name' => 'Vereinigtes Königreich', 'nationality' => 'Britisch'],
                    'fr' => ['name' => 'Royaume-Uni', 'nationality' => 'Britannique'],
                ],
            ],
            [
                'iso_code_2' => 'US',
                'iso_code_3' => 'USA',
                'numeric_code' => '840',
                'phone_prefix' => '+1',
                'is_eu_member' => false,
                'is_sepa_member' => false,
                'currency_code' => 'USD',
                'translations' => [
                    'it' => ['name' => 'Stati Uniti', 'nationality' => 'Americana'],
                    'en' => ['name' => 'United States', 'nationality' => 'American'],
                    'de' => ['name' => 'Vereinigte Staaten', 'nationality' => 'Amerikanisch'],
                    'fr' => ['name' => 'États-Unis', 'nationality' => 'Américaine'],
                ],
            ],
        ];

        DB::transaction(function () use ($countries): void {
            foreach ($countries as $countryData) {
                /** @var Country $country */
                $country = Country::updateOrCreate(
                    ['iso_code_2' => $countryData['iso_code_2']],
                    [
                        'iso_code_3' => $countryData['iso_code_3'],
                        'numeric_code' => $countryData['numeric_code'],
                        'phone_prefix' => $countryData['phone_prefix'],
                        'is_eu_member' => $countryData['is_eu_member'],
                        'is_sepa_member' => $countryData['is_sepa_member'],
                        'currency_code' => $countryData['currency_code'],
                        'is_active' => true,
                    ]
                );

                foreach ($countryData['translations'] as $locale => $trans) {
                    CountryTranslation::updateOrCreate(
                        [
                            'country_id' => $country->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'nationality' => $trans['nationality'],
                        ]
                    );
                }
            }
        });
    }
}
