<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\ExpenseCategoryTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'MEALS_ENTERTAINMENT',
                'requires_receipt' => true,
                'suggested_account_code' => '6200',
                'translations' => [
                    'it' => ['name' => 'Vitto e Spese di Rappresentanza', 'description' => 'Pranzi di lavoro, cene con clienti e spese di ospitalità'],
                    'en' => ['name' => 'Meals & Client Entertainment', 'description' => 'Business lunches, dining with clients, and hospitality expenses'],
                    'de' => ['name' => 'Verpflegung & Bewirtung', 'description' => 'Geschäftsessen, Kundenbewirtung und Repräsentationsspesen'],
                    'fr' => ['name' => 'Repas & Frais de réception', 'description' => 'Déjeuners d\'affaires, réceptions de clients et frais d\'accueil'],
                ],
            ],
            [
                'code' => 'LODGING_HOTEL',
                'requires_receipt' => true,
                'suggested_account_code' => '6210',
                'translations' => [
                    'it' => ['name' => 'Alloggio e Hotel', 'description' => 'Pernottamenti alberghieri durante trasferte di lavoro autorizzate'],
                    'en' => ['name' => 'Lodging & Accommodation', 'description' => 'Hotel stays and lodging during authorized corporate travel'],
                    'de' => ['name' => 'Übernachtung & Hotel', 'description' => 'Hotelübernachtungen während genehmigter Dienstreisen'],
                    'fr' => ['name' => 'Hébergement & Hôtel', 'description' => 'Nuits d\'hôtel et hébergement lors de déplacements professionnels'],
                ],
            ],
            [
                'code' => 'TRAVEL_TRANSPORT',
                'requires_receipt' => true,
                'suggested_account_code' => '6220',
                'translations' => [
                    'it' => ['name' => 'Trasporti e Biglietteria', 'description' => 'Treni, aerei, taxi, pedaggi autostradali e mezzi pubblici'],
                    'en' => ['name' => 'Travel & Transportation', 'description' => 'Flights, train tickets, taxis, tolls, and public transit fares'],
                    'de' => ['name' => 'Reisekosten & Transport', 'description' => 'Flugtickets, Bahnfahrkarten, Taxis, Maut und öffentliche Verkehrsmittel'],
                    'fr' => ['name' => 'Voyages & Transports', 'description' => 'Billets de train, avion, taxis, péages et transports en commun'],
                ],
            ],
            [
                'code' => 'FUEL_MILEAGE',
                'requires_receipt' => true,
                'suggested_account_code' => '6230',
                'translations' => [
                    'it' => ['name' => 'Carburante e Rimborso Chilometrico', 'description' => 'Spese di rifornimento o rimborso chilometrico tabelle ACI'],
                    'en' => ['name' => 'Fuel & Mileage Reimbursement', 'description' => 'Vehicle fuel purchases or standard corporate mileage allowance'],
                    'de' => ['name' => 'Kraftstoff & Kilometergeld', 'description' => 'Treibstoffausgaben oder amtliche Kilometerpauschale'],
                    'fr' => ['name' => 'Carburant & Indemnités kilométriques', 'description' => 'Carburant pour véhicule ou remboursement kilométrique selon barème'],
                ],
            ],
            [
                'code' => 'OFFICE_SUPPLIES',
                'requires_receipt' => true,
                'suggested_account_code' => '6300',
                'translations' => [
                    'it' => ['name' => 'Cancelleria e Materiale d\'Ufficio', 'description' => 'Acquisto materiale di consumo e piccoli accessori per ufficio'],
                    'en' => ['name' => 'Office Supplies & Small Equipment', 'description' => 'Stationery, office consumables, and minor IT peripherals'],
                    'de' => ['name' => 'Bürobedarf & Kleinmaterial', 'description' => 'Schreibwaren, Büromaterial und IT-Verbrauchsgüter'],
                    'fr' => ['name' => 'Fournitures de bureau & Petit matériel', 'description' => 'Papeterie, consommables de bureau et petits équipements'],
                ],
            ],
        ];

        DB::transaction(function () use ($categories): void {
            foreach ($categories as $catData) {
                /** @var ExpenseCategory $cat */
                $cat = ExpenseCategory::updateOrCreate(
                    ['code' => $catData['code']],
                    [
                        'requires_receipt' => $catData['requires_receipt'],
                        'suggested_account_code' => $catData['suggested_account_code'],
                        'is_active' => true,
                    ]
                );

                foreach ($catData['translations'] as $locale => $trans) {
                    ExpenseCategoryTranslation::updateOrCreate(
                        [
                            'expense_category_id' => $cat->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }
        });
    }
}
