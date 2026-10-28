<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class InventoryStatusSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'inventory_count_status',
                'name' => 'Cycle Count Session Status',
                'description' => 'Status lifecycle for warehouse physical inventory count sessions',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'in_progress', 'sort' => 20, 'translations' => ['en' => 'In Progress', 'it' => 'In Corso', 'de' => 'In Bearbeitung', 'fr' => 'En cours']],
                    ['code' => 'completed', 'sort' => 30, 'translations' => ['en' => 'Completed', 'it' => 'Completato', 'de' => 'Abgeschlossen', 'fr' => 'Terminé']],
                    ['code' => 'cancelled', 'sort' => 40, 'translations' => ['en' => 'Cancelled', 'it' => 'Annullato', 'de' => 'Storniert', 'fr' => 'Annulé']],
                ],
            ],
            [
                'code' => 'inventory_adjustment_status',
                'name' => 'Stock Adjustment Status',
                'description' => 'Approval lifecycle for quantity and value inventory adjustments',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'pending_approval', 'sort' => 20, 'translations' => ['en' => 'Pending Approval', 'it' => 'In Attesa di Approvazione', 'de' => 'Genehmigung ausstehend', 'fr' => 'En attente d\'approbation']],
                    ['code' => 'approved', 'sort' => 30, 'translations' => ['en' => 'Approved', 'it' => 'Approvato', 'de' => 'Genehmigt', 'fr' => 'Approuvé']],
                    ['code' => 'rejected', 'sort' => 40, 'translations' => ['en' => 'Rejected', 'it' => 'Respinto', 'de' => 'Abgelehnt', 'fr' => 'Rejeté']],
                ],
            ],
            [
                'code' => 'stock_pick_status',
                'name' => 'Pick List Status',
                'description' => 'Warehouse picking and dispatch status',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'assigned', 'sort' => 20, 'translations' => ['en' => 'Assigned', 'it' => 'Assegnato', 'de' => 'Zugewiesen', 'fr' => 'Assigné']],
                    ['code' => 'picking', 'sort' => 30, 'translations' => ['en' => 'Picking in Progress', 'it' => 'Prelievo in Corso', 'de' => 'Kommissionierung', 'fr' => 'En préparation']],
                    ['code' => 'completed', 'sort' => 40, 'translations' => ['en' => 'Completed', 'it' => 'Completato', 'de' => 'Abgeschlossen', 'fr' => 'Terminé']],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $cat = LookupCategory::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
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
                        'is_default' => $item['code'] === 'draft',
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
