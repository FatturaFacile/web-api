<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Region;
use App\Models\RegionTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RegionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ch = Country::where('iso_code_2', 'CH')->first();
        $it = Country::where('iso_code_2', 'IT')->first();
        $de = Country::where('iso_code_2', 'DE')->first();
        $fr = Country::where('iso_code_2', 'FR')->first();

        if (! $ch || ! $it) {
            return;
        }

        $regions = [
            // Swiss Cantons (Official 26 Cantons)
            [
                'country_id' => $ch->id,
                'code' => 'TI',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Ticino',
                    'en' => 'Ticino',
                    'de' => 'Tessin',
                    'fr' => 'Tessin',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'ZH',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Zurigo',
                    'en' => 'Zurich',
                    'de' => 'Zürich',
                    'fr' => 'Zurich',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'GE',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Ginevra',
                    'en' => 'Geneva',
                    'de' => 'Genf',
                    'fr' => 'Genève',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'VD',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Vaud',
                    'en' => 'Vaud',
                    'de' => 'Waadt',
                    'fr' => 'Vaud',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'BE',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Berna',
                    'en' => 'Bern',
                    'de' => 'Bern',
                    'fr' => 'Berne',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'BS',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Basilea Città',
                    'en' => 'Basel-City',
                    'de' => 'Basel-Stadt',
                    'fr' => 'Bâle-Ville',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'LU',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Lucerna',
                    'en' => 'Lucerne',
                    'de' => 'Luzern',
                    'fr' => 'Lucerne',
                ],
            ],
            [
                'country_id' => $ch->id,
                'code' => 'GR',
                'type' => 'canton',
                'translations' => [
                    'it' => 'Grigioni',
                    'en' => 'Grisons',
                    'de' => 'Graubünden',
                    'fr' => 'Grisons',
                ],
            ],
            // Key Italian Provinces (Cross-border Lombardy, Piedmont, Rome, South Tyrol)
            [
                'country_id' => $it->id,
                'code' => 'MI',
                'type' => 'province',
                'translations' => [
                    'it' => 'Milano',
                    'en' => 'Milan',
                    'de' => 'Mailand',
                    'fr' => 'Milan',
                ],
            ],
            [
                'country_id' => $it->id,
                'code' => 'CO',
                'type' => 'province',
                'translations' => [
                    'it' => 'Como',
                    'en' => 'Como',
                    'de' => 'Como',
                    'fr' => 'Côme',
                ],
            ],
            [
                'country_id' => $it->id,
                'code' => 'VA',
                'type' => 'province',
                'translations' => [
                    'it' => 'Varese',
                    'en' => 'Varese',
                    'de' => 'Varese',
                    'fr' => 'Varèse',
                ],
            ],
            [
                'country_id' => $it->id,
                'code' => 'BZ',
                'type' => 'province',
                'translations' => [
                    'it' => 'Bolzano',
                    'en' => 'South Tyrol',
                    'de' => 'Bozen-Südtirol',
                    'fr' => 'Bolzano',
                ],
            ],
            [
                'country_id' => $it->id,
                'code' => 'RM',
                'type' => 'province',
                'translations' => [
                    'it' => 'Roma',
                    'en' => 'Rome',
                    'de' => 'Rom',
                    'fr' => 'Rome',
                ],
            ],
            [
                'country_id' => $it->id,
                'code' => 'TO',
                'type' => 'province',
                'translations' => [
                    'it' => 'Torino',
                    'en' => 'Turin',
                    'de' => 'Turin',
                    'fr' => 'Turin',
                ],
            ],
        ];

        if ($de) {
            $regions[] = [
                'country_id' => $de->id,
                'code' => 'BY',
                'type' => 'state',
                'translations' => [
                    'it' => 'Baviera',
                    'en' => 'Bavaria',
                    'de' => 'Bayern',
                    'fr' => 'Bavière',
                ],
            ];
            $regions[] = [
                'country_id' => $de->id,
                'code' => 'BW',
                'type' => 'state',
                'translations' => [
                    'it' => 'Baden-Württemberg',
                    'en' => 'Baden-Württemberg',
                    'de' => 'Baden-Württemberg',
                    'fr' => 'Bade-Wurtemberg',
                ],
            ];
        }

        if ($fr) {
            $regions[] = [
                'country_id' => $fr->id,
                'code' => '75',
                'type' => 'department',
                'translations' => [
                    'it' => 'Parigi',
                    'en' => 'Paris',
                    'de' => 'Paris',
                    'fr' => 'Paris',
                ],
            ];
            $regions[] = [
                'country_id' => $fr->id,
                'code' => '69',
                'type' => 'department',
                'translations' => [
                    'it' => 'Rodano (Lione)',
                    'en' => 'Rhone (Lyon)',
                    'de' => 'Rhône (Lyon)',
                    'fr' => 'Rhône (Lyon)',
                ],
            ];
        }

        DB::transaction(function () use ($regions): void {
            foreach ($regions as $regionData) {
                /** @var Region $region */
                $region = Region::updateOrCreate(
                    [
                        'country_id' => $regionData['country_id'],
                        'code' => $regionData['code'],
                    ],
                    [
                        'type' => $regionData['type'],
                    ]
                );

                foreach ($regionData['translations'] as $locale => $name) {
                    RegionTranslation::updateOrCreate(
                        [
                            'region_id' => $region->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $name,
                        ]
                    );
                }
            }
        });
    }
}
