<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

class ExpenseLookupSeeder extends Seeder
{
    /**
     * Run the expense category multilingual lookups.
     */
    public function run(): void
    {
        $categoriesData = [
            [
                'code' => 'expense_category',
                'name' => 'Expense Categories',
                'description' => 'Standard expense and cost categorization for small businesses, contractors, and field service technicians.',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'fuel',
                        'sort_order' => 10,
                        'translations' => [
                            'en' => ['name' => 'Fuel & Gasoline', 'description' => 'Diesel, petrol, electric charging and vehicle fuel'],
                            'it' => ['name' => 'Carburante', 'description' => 'Gasolio, benzina, ricarica elettrica per veicoli aziendali'],
                            'de' => ['name' => 'Treibstoff & Benzin', 'description' => 'Diesel, Benzin, Stromladung für Firmenfahrzeuge'],
                            'fr' => ['name' => 'Carburant', 'description' => 'Diesel, essence, recharge électrique pour véhicules'],
                        ],
                    ],
                    [
                        'code' => 'materials',
                        'sort_order' => 20,
                        'translations' => [
                            'en' => ['name' => 'Materials & Supplies', 'description' => 'Pipes, cables, fittings, spare parts and consumables'],
                            'it' => ['name' => 'Materiali e Forniture', 'description' => 'Tubature, cavi, raccordi, ricambi e materiali di consumo'],
                            'de' => ['name' => 'Material & Verbrauchsgüter', 'description' => 'Rohre, Kabel, Armaturen, Ersatzteile und Verbrauchsmaterialien'],
                            'fr' => ['name' => 'Matériaux et Fournitures', 'description' => 'Tuyaux, câbles, raccords, pièces de rechange et consommables'],
                        ],
                    ],
                    [
                        'code' => 'parking',
                        'sort_order' => 30,
                        'translations' => [
                            'en' => ['name' => 'Parking', 'description' => 'On-street parking, garages and client visit parking tickets'],
                            'it' => ['name' => 'Parcheggio', 'description' => 'Soste su strada, autorimesse e ticket parcheggio presso clienti'],
                            'de' => ['name' => 'Parkgebühren', 'description' => 'Straßenparken, Parkhäuser und Parktickets'],
                            'fr' => ['name' => 'Stationnement', 'description' => 'Stationnement dans la rue, garages et tickets de parking'],
                        ],
                    ],
                    [
                        'code' => 'tolls',
                        'sort_order' => 40,
                        'translations' => [
                            'en' => ['name' => 'Highway Tolls & Vignettes', 'description' => 'Motorway tolls, Telepass, Swiss motorway vignette and tunnel fees'],
                            'it' => ['name' => 'Pedaggi e Vignette', 'description' => 'Pedaggi autostradali, Telepass, vignetta svizzera e trafori'],
                            'de' => ['name' => 'Maut & Autobahnvignette', 'description' => 'Autobahngebühren, Maut, Schweizer Autobahnvignette und Tunnelgebühren'],
                            'fr' => ['name' => 'Péages et Vignettes', 'description' => 'Péages d’autoroute, télépéage, vignette suisse et tunnels'],
                        ],
                    ],
                    [
                        'code' => 'tools',
                        'sort_order' => 50,
                        'translations' => [
                            'en' => ['name' => 'Tools & Equipment', 'description' => 'Hand tools, power tools, drills, safety PPE and job equipment'],
                            'it' => ['name' => 'Attrezzi e Utensili', 'description' => 'Attrezzi manuali, elettroutensili, punte, DPI antinfortunistici'],
                            'de' => ['name' => 'Werkzeuge & Ausrüstung', 'description' => 'Handwerkzeuge, Elektrowerkzeuge, Bohrer, PSA und Ausrüstung'],
                            'fr' => ['name' => 'Outils et Équipements', 'description' => 'Outils à main, électroportatifs, forets, EPI et équipements'],
                        ],
                    ],
                    [
                        'code' => 'subcontractors',
                        'sort_order' => 60,
                        'translations' => [
                            'en' => ['name' => 'Subcontractors & Specialist Labor', 'description' => 'External specialized technicians, electricians, bricklayers'],
                            'it' => ['name' => 'Subappalti e Lavorazioni Esterne', 'description' => 'Tecnici specializzati esterni, cottimisti, manovalanza'],
                            'de' => ['name' => 'Subunternehmer & Fremdleistungen', 'description' => 'Externe Fachkräfte, Subunternehmer und Spezialarbeiten'],
                            'fr' => ['name' => 'Sous-traitance et Prestations Externes', 'description' => 'Techniciens externes spécialisés et main d’œuvre extérieure'],
                        ],
                    ],
                    [
                        'code' => 'travel',
                        'sort_order' => 70,
                        'translations' => [
                            'en' => ['name' => 'Travel & Lodging', 'description' => 'Train tickets, flights, hotel stays for on-site projects'],
                            'it' => ['name' => 'Trasferte e Alloggio', 'description' => 'Biglietti treno, aerei, pernottamenti in hotel per cantieri e trasferte'],
                            'de' => ['name' => 'Reisen & Übernachtung', 'description' => 'Zugtickets, Flüge, Hotelübernachtungen für Montageeinsätze'],
                            'fr' => ['name' => 'Déplacements et Hébergement', 'description' => 'Billets de train, avions, nuits d’hôtel pour chantiers'],
                        ],
                    ],
                    [
                        'code' => 'meals',
                        'sort_order' => 80,
                        'translations' => [
                            'en' => ['name' => 'Meals & Subsistence', 'description' => 'Lunch, dinner, coffee and technician subsistence during field visits'],
                            'it' => ['name' => 'Pasti e Ristorazione', 'description' => 'Pranzi di lavoro, cene, caffè e vitto tecnici in cantiere'],
                            'de' => ['name' => 'Verpflegung & Spesen', 'description' => 'Geschäftsessen, Mittagessen, Kaffee und Spesen auf Baustellen'],
                            'fr' => ['name' => 'Repas et Restauration', 'description' => 'Repas de travail, déjeuners et restauration des techniciens'],
                        ],
                    ],
                    [
                        'code' => 'office',
                        'sort_order' => 90,
                        'translations' => [
                            'en' => ['name' => 'Office & Administrative', 'description' => 'Stationery, printer toner, postage, software subscriptions'],
                            'it' => ['name' => 'Spese Ufficio e Amministrative', 'description' => 'Cancelleria, toner, spedizioni postali, abbonamenti software'],
                            'de' => ['name' => 'Bürobedarf & Verwaltung', 'description' => 'Schreibwaren, Toner, Portokosten, Software-Abonnements'],
                            'fr' => ['name' => 'Bureautique et Administration', 'description' => 'Fournitures de bureau, toner, affranchissement, logiciels'],
                        ],
                    ],
                    [
                        'code' => 'other',
                        'sort_order' => 100,
                        'translations' => [
                            'en' => ['name' => 'Other / Miscellaneous', 'description' => 'Miscellaneous business costs not covered elsewhere'],
                            'it' => ['name' => 'Altro / Spese Varie', 'description' => 'Costi aziendali diversi non classificati altrove'],
                            'de' => ['name' => 'Sonstiges / Verschiedenes', 'description' => 'Sonstige betriebliche Ausgaben'],
                            'fr' => ['name' => 'Autre / Divers', 'description' => 'Autres dépenses professionnelles non classées ailleurs'],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($categoriesData as $catData) {
            /** @var LookupCategory $category */
            $category = LookupCategory::updateOrCreate(
                ['code' => $catData['code']],
                [
                    'name' => $catData['name'],
                    'description' => $catData['description'],
                    'is_system' => $catData['is_system'],
                ]
            );

            foreach ($catData['items'] as $itemData) {
                /** @var LookupItem $item */
                $item = LookupItem::updateOrCreate(
                    [
                        'category_id' => $category->id,
                        'code' => $itemData['code'],
                    ],
                    [
                        'sort_order' => $itemData['sort_order'],
                        'is_active' => true,
                        'is_default' => $itemData['code'] === 'materials',
                    ]
                );

                foreach ($itemData['translations'] as $locale => $trans) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $item->id,
                            'locale' => $locale,
                        ],
                        [
                            'label' => $trans['name'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }
        }
    }
}
