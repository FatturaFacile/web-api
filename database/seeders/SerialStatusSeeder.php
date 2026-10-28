<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class SerialStatusSeeder extends Seeder
{
    public function run(): void
    {
        $cat = LookupCategory::updateOrCreate(
            ['code' => 'stock_serial_status'],
            [
                'name' => 'Stock Serial Number Statuses',
                'description' => 'Lifecycle statuses of serialized items and equipment',
                'is_system' => true,
            ]
        );

        $statuses = [
            [
                'code' => 'in_stock',
                'translations' => [
                    'en' => 'In Stock / Available',
                    'it' => 'In Giacenza / Disponibile',
                    'de' => 'Auf Lager / Verfügbar',
                    'fr' => 'En stock / Disponible',
                ],
                'sort' => 10,
            ],
            [
                'code' => 'allocated',
                'translations' => [
                    'en' => 'Allocated / Reserved',
                    'it' => 'Allocato / Riservato',
                    'de' => 'Reserviert',
                    'fr' => 'Alloué / Réservé',
                ],
                'sort' => 20,
            ],
            [
                'code' => 'issued',
                'translations' => [
                    'en' => 'Issued to Production / Project',
                    'it' => 'Emesso a Produzione / Progetto',
                    'de' => 'Ausgegeben für Produktion / Projekt',
                    'fr' => 'Sorti en production / projet',
                ],
                'sort' => 30,
            ],
            [
                'code' => 'shipped',
                'translations' => [
                    'en' => 'Shipped / Delivered to Customer',
                    'it' => 'Spedito / Consegnato al Cliente',
                    'de' => 'Versendet / An Kunden geliefert',
                    'fr' => 'Expédié / Livré au client',
                ],
                'sort' => 40,
            ],
            [
                'code' => 'returned',
                'translations' => [
                    'en' => 'Returned for RMA / Inspection',
                    'it' => 'Reso per RMA / Ispezione',
                    'de' => 'Rücksendung / RMA',
                    'fr' => 'Retourné pour SAV / inspection',
                ],
                'sort' => 50,
            ],
            [
                'code' => 'scrapped',
                'translations' => [
                    'en' => 'Scrapped / Decommissioned',
                    'it' => 'Rottamato / Dismesso',
                    'de' => 'Verschrottet / Außer Betrieb',
                    'fr' => 'Mis au rebut / déclassé',
                ],
                'sort' => 60,
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
                    'is_default' => $st['code'] === 'in_stock',
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
