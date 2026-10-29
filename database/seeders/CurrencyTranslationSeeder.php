<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\CurrencyTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencyTranslationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'precision' => 2,
                'translations' => [
                    'it' => ['name' => 'Euro', 'subunit_name' => 'Centesimo'],
                    'en' => ['name' => 'Euro', 'subunit_name' => 'Cent'],
                    'de' => ['name' => 'Euro', 'subunit_name' => 'Cent'],
                    'fr' => ['name' => 'Euro', 'subunit_name' => 'Centime'],
                ],
            ],
            [
                'code' => 'CHF',
                'name' => 'Swiss Franc',
                'symbol' => 'CHF',
                'precision' => 2,
                'translations' => [
                    'it' => ['name' => 'Franco Svizzero', 'subunit_name' => 'Centesimo'],
                    'en' => ['name' => 'Swiss Franc', 'subunit_name' => 'Centime'],
                    'de' => ['name' => 'Schweizer Franken', 'subunit_name' => 'Rappen'],
                    'fr' => ['name' => 'Franc Suisse', 'subunit_name' => 'Centime'],
                ],
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'precision' => 2,
                'translations' => [
                    'it' => ['name' => 'Dollaro Statunitense', 'subunit_name' => 'Cent'],
                    'en' => ['name' => 'US Dollar', 'subunit_name' => 'Cent'],
                    'de' => ['name' => 'US-Dollar', 'subunit_name' => 'Cent'],
                    'fr' => ['name' => 'Dollar Américain', 'subunit_name' => 'Cent'],
                ],
            ],
            [
                'code' => 'GBP',
                'name' => 'British Pound',
                'symbol' => '£',
                'precision' => 2,
                'translations' => [
                    'it' => ['name' => 'Sterlina Britannica', 'subunit_name' => 'Penny'],
                    'en' => ['name' => 'British Pound', 'subunit_name' => 'Penny'],
                    'de' => ['name' => 'Britisches Pfund', 'subunit_name' => 'Penny'],
                    'fr' => ['name' => 'Livre Sterling', 'subunit_name' => 'Penny'],
                ],
            ],
        ];

        DB::transaction(function () use ($currencies): void {
            foreach ($currencies as $currencyData) {
                /** @var Currency $currency */
                $currency = Currency::updateOrCreate(
                    ['code' => $currencyData['code']],
                    [
                        'name' => $currencyData['name'],
                        'symbol' => $currencyData['symbol'],
                        'precision' => $currencyData['precision'],
                        'is_active' => true,
                    ]
                );

                foreach ($currencyData['translations'] as $locale => $trans) {
                    CurrencyTranslation::updateOrCreate(
                        [
                            'currency_id' => $currency->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'subunit_name' => $trans['subunit_name'],
                        ]
                    );
                }
            }
        });
    }
}
