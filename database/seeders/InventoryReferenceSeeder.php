<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class InventoryReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'inventory_valuation_methods',
                'name' => 'Inventory Valuation Methods',
                'description' => 'Accounting methods for inventory valuation and cost of goods sold',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'FIFO',
                        'translations' => [
                            'en' => 'First In, First Out (FIFO)',
                            'it' => 'Primo Entrato, Primo Uscito (FIFO)',
                            'de' => 'First In, First Out (FIFO)',
                            'fr' => 'Premier entré, premier sorti (FIFO)',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'AVCO',
                        'translations' => [
                            'en' => 'Moving Weighted Average Cost (AVCO)',
                            'it' => 'Costo Medio Ponderato Mobile (CMP)',
                            'de' => 'Gleitender Durchschnittspreis',
                            'fr' => 'Coût unitaire moyen pondéré (CUMP)',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'STANDARD',
                        'translations' => [
                            'en' => 'Standard Costing',
                            'it' => 'Costo Standard',
                            'de' => 'Standardkostenrechnung',
                            'fr' => 'Coût standard',
                        ],
                        'sort' => 30,
                    ],
                ],
            ],
            [
                'code' => 'storage_conditions',
                'name' => 'Storage Environmental Conditions',
                'description' => 'Temperature, moisture and environmental storage requirements',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'ambient',
                        'translations' => [
                            'en' => 'Ambient (15°C - 25°C)',
                            'it' => 'Ambiente (15°C - 25°C)',
                            'de' => 'Raumtemperatur (15°C - 25°C)',
                            'fr' => 'Ambiante (15°C - 25°C)',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'chilled',
                        'translations' => [
                            'en' => 'Chilled / Cold Storage (2°C - 8°C)',
                            'it' => 'Refrigerato (2°C - 8°C)',
                            'de' => 'Gekühlt (2°C - 8°C)',
                            'fr' => 'Réfrigéré (2°C - 8°C)',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'frozen',
                        'translations' => [
                            'en' => 'Deep Frozen (-18°C or lower)',
                            'it' => 'Surgelato (-18°C o inferiore)',
                            'de' => 'Tiefgekühlt (-18°C oder tiefer)',
                            'fr' => 'Surgelé (-18°C ou inférieur)',
                        ],
                        'sort' => 30,
                    ],
                    [
                        'code' => 'dry_air',
                        'translations' => [
                            'en' => 'Dry / Moisture Sensitive (<40% RH)',
                            'it' => 'Asciutto / Sensibile all\'umidità (<40% UR)',
                            'de' => 'Trocken (<40% r.F.)',
                            'fr' => 'Sec (<40% HR)',
                        ],
                        'sort' => 40,
                    ],
                    [
                        'code' => 'hazardous',
                        'translations' => [
                            'en' => 'Hazardous / Chemical Spill Containment',
                            'it' => 'Merci Pericolose / Contenimento Chimico',
                            'de' => 'Gefahrgut / Chemikalienlager',
                            'fr' => 'Matières dangereuses',
                        ],
                        'sort' => 50,
                    ],
                ],
            ],
            [
                'code' => 'abc_classification',
                'name' => 'ABC Inventory Analysis Categories',
                'description' => 'Pareto analysis categories based on consumption value',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'A',
                        'translations' => [
                            'en' => 'Class A: High Value (Top 80% Value, ~20% Items)',
                            'it' => 'Classe A: Alto Valore (80% Valore, ~20% Articoli)',
                            'de' => 'Klasse A: Hoher Wert (80% Wert, ~20% Positionen)',
                            'fr' => 'Classe A: Haute valeur (80% Valeur, ~20% Articles)',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'B',
                        'translations' => [
                            'en' => 'Class B: Medium Value (15% Value, ~30% Items)',
                            'it' => 'Classe B: Medio Valore (15% Valore, ~30% Articoli)',
                            'de' => 'Klasse B: Mittlerer Wert (15% Wert, ~30% Positionen)',
                            'fr' => 'Classe B: Valeur moyenne (15% Valeur, ~30% Articles)',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'C',
                        'translations' => [
                            'en' => 'Class C: Low Value (5% Value, ~50% Items)',
                            'it' => 'Classe C: Basso Valore (5% Valore, ~50% Articoli)',
                            'de' => 'Klasse C: Geringer Wert (5% Wert, ~50% Positionen)',
                            'fr' => 'Classe C: Faible valeur (5% Valeur, ~50% Articles)',
                        ],
                        'sort' => 30,
                    ],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $cat = LookupCategory::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => $def['is_system'],
                ]
            );

            foreach ($def['items'] as $item) {
                $lookupItem = LookupItem::updateOrCreate(
                    [
                        'category_id' => $cat->id,
                        'code' => $item['code'],
                    ],
                    [
                        'sort_order' => $item['sort'],
                        'is_active' => true,
                        'is_default' => $item['code'] === 'FIFO' || $item['code'] === 'ambient' || $item['code'] === 'A',
                    ]
                );

                foreach (['en', 'it', 'de', 'fr'] as $lang) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $lookupItem->id,
                            'locale' => $lang,
                        ],
                        [
                            'label' => $item['translations'][$lang],
                            'description' => $item['translations'][$lang],
                        ]
                    );
                }
            }
        }
    }
}
