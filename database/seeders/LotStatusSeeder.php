<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class LotStatusSeeder extends Seeder
{
    public function run(): void
    {
        $cat = LookupCategory::updateOrCreate(
            ['code' => 'stock_lot_status'],
            [
                'name' => 'Stock Lot Statuses',
                'description' => 'Lifecycle statuses of inventory batches and lots',
                'is_system' => true,
            ]
        );

        $statuses = [
            [
                'code' => 'active',
                'translations' => [
                    'en' => 'Active / Released',
                    'it' => 'Attivo / Rilasciato',
                    'de' => 'Aktiv / Freigegeben',
                    'fr' => 'Actif / Libéré',
                ],
                'sort' => 10,
            ],
            [
                'code' => 'quarantined',
                'translations' => [
                    'en' => 'Quarantined / On Hold',
                    'it' => 'In Quarantena / Bloccato',
                    'de' => 'Gesperrt / In Quarantäne',
                    'fr' => 'En quarantaine / Suspendu',
                ],
                'sort' => 20,
            ],
            [
                'code' => 'expired',
                'translations' => [
                    'en' => 'Expired',
                    'it' => 'Scaduto',
                    'de' => 'Abgelaufen',
                    'fr' => 'Expiré',
                ],
                'sort' => 30,
            ],
            [
                'code' => 'recalled',
                'translations' => [
                    'en' => 'Recalled',
                    'it' => 'Richiamato',
                    'de' => 'Zurückgerufen',
                    'fr' => 'Rappelé',
                ],
                'sort' => 40,
            ],
        ];

        foreach ($statuses as $st) {
            $lookupItem = LookupItem::updateOrCreate(
                [
                    'category_id' => $cat->id,
                    'code' => $st['code'],
                ],
                [
                    'sort_order' => $st['sort'],
                    'is_active' => true,
                    'is_default' => $st['code'] === 'active',
                ]
            );

            foreach (['en', 'it', 'de', 'fr'] as $lang) {
                LookupItemTranslation::updateOrCreate(
                    [
                        'lookup_item_id' => $lookupItem->id,
                        'locale' => $lang,
                    ],
                    [
                        'label' => $st['translations'][$lang],
                        'description' => $st['translations'][$lang],
                    ]
                );
            }
        }
    }
}
