<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class InventoryLookupSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'warehouse_zone_type',
                'name' => 'Warehouse Zone Types',
                'description' => 'Functional zoning categories within logistics facilities',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'standard_racking',
                        'translations' => [
                            'en' => 'Standard Pallet Racking',
                            'it' => 'Scaffalatura Portapallet Standard',
                            'de' => 'Standard-Palettenregallager',
                            'fr' => 'Rayonnage à palettes standard',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'cold_storage',
                        'translations' => [
                            'en' => 'Cold Storage / Controlled Atmosphere',
                            'it' => 'Cella Frigorifera / Atmosfera Controllata',
                            'de' => 'Kühllager / Kontrollierte Atmosphäre',
                            'fr' => 'Chambre froide / Atmosphère contrôlée',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'hazardous_materials',
                        'translations' => [
                            'en' => 'Hazmat Storage',
                            'it' => 'Stoccaggio Merci Pericolose',
                            'de' => 'Gefahrstofflager',
                            'fr' => 'Stockage matières dangereuses',
                        ],
                        'sort' => 30,
                    ],
                    [
                        'code' => 'staging_inbound',
                        'translations' => [
                            'en' => 'Inbound Staging Area',
                            'it' => 'Area Ricevimento Merci',
                            'de' => 'Wareneingangszone',
                            'fr' => 'Zone de réception et contrôle',
                        ],
                        'sort' => 40,
                    ],
                    [
                        'code' => 'staging_outbound',
                        'translations' => [
                            'en' => 'Outbound Staging Area',
                            'it' => 'Area Spedizione Merci',
                            'de' => 'Warenausgangszone',
                            'fr' => 'Zone d\'expédition et préparation',
                        ],
                        'sort' => 50,
                    ],
                    [
                        'code' => 'quarantine_zone',
                        'translations' => [
                            'en' => 'Quarantine Zone',
                            'it' => 'Area Quarantena',
                            'de' => 'Quarantänebereich',
                            'fr' => 'Zone de quarantaine',
                        ],
                        'sort' => 60,
                    ],
                ],
            ],
            [
                'code' => 'bin_type',
                'name' => 'Warehouse Bin Types',
                'description' => 'Specific bin and location functional purposes',
                'is_system' => true,
                'items' => [
                    [
                        'code' => 'standard',
                        'translations' => [
                            'en' => 'Standard Storage Bin',
                            'it' => 'Ubicazione di Stoccaggio Standard',
                            'de' => 'Standard-Lagerfach',
                            'fr' => 'Emplacement de stockage standard',
                        ],
                        'sort' => 10,
                    ],
                    [
                        'code' => 'inbound',
                        'translations' => [
                            'en' => 'Inbound / Dock Receiving Bin',
                            'it' => 'Ubicazione Banchina Ricevimento',
                            'de' => 'Wareneingangs-Lagerfach',
                            'fr' => 'Emplacement de réception',
                        ],
                        'sort' => 20,
                    ],
                    [
                        'code' => 'outbound',
                        'translations' => [
                            'en' => 'Outbound / Staging Bin',
                            'it' => 'Ubicazione Banchina Spedizione',
                            'de' => 'Warenausgangs-Lagerfach',
                            'fr' => 'Emplacement de préparation / sortie',
                        ],
                        'sort' => 30,
                    ],
                    [
                        'code' => 'quarantine',
                        'translations' => [
                            'en' => 'Quarantine Bin',
                            'it' => 'Ubicazione di Quarantena',
                            'de' => 'Quarantänefach',
                            'fr' => 'Emplacement de quarantaine',
                        ],
                        'sort' => 40,
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
                        'is_default' => $item['code'] === 'standard_racking' || $item['code'] === 'standard',
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
