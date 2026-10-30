<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProcurementReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $criteria = [
            [
                'code' => 'OTIF',
                'names' => [
                    'en' => 'On-Time In-Full Delivery Rate',
                    'it' => 'Tasso di Consegna Puntuale e Completa',
                    'de' => 'Pünktliche und Vollständige Lieferquote',
                    'fr' => 'Taux de livraison à temps et complète',
                ],
                'weight' => 35,
            ],
            [
                'code' => 'QUALITY_PPM',
                'names' => [
                    'en' => 'Quality & Defect Rate (PPM)',
                    'it' => 'Qualità e Tasso di Difettosità (PPM)',
                    'de' => 'Qualität & Fehlerrate (PPM)',
                    'fr' => 'Qualité et taux de non-conformité (PPM)',
                ],
                'weight' => 30,
            ],
            [
                'code' => 'PRICE_COMPLIANCE',
                'names' => [
                    'en' => 'Price & Invoicing Accuracy',
                    'it' => 'Accuratezza Prezzi e Fatturazione',
                    'de' => 'Preis- und Abrechnungsgenauigkeit',
                    'fr' => 'Exactitude des prix et facturation',
                ],
                'weight' => 20,
            ],
            [
                'code' => 'ESG_SUSTAINABILITY',
                'names' => [
                    'en' => 'ESG Compliance & Sustainability',
                    'it' => 'Conformità ESG e Sostenibilità',
                    'de' => 'ESG-Konformität & Nachhaltigkeit',
                    'fr' => 'Conformité ESG et durabilité',
                ],
                'weight' => 15,
            ],
        ];

        $category = LookupCategory::updateOrCreate(
            ['code' => 'supplier_evaluation_criterion'],
            [
                'name' => 'Supplier Evaluation Criteria',
                'description' => 'Standard criteria for evaluating vendor performance',
                'is_system' => true,
            ]
        );

        $sort = 10;
        foreach ($criteria as $crit) {
            $item = LookupItem::updateOrCreate(
                [
                    'category_id' => $category->id,
                    'code' => $crit['code'],
                ],
                [
                    'sort_order' => $sort,
                    'is_active' => true,
                    'is_default' => false,
                ]
            );
            $sort += 10;

            foreach (['en', 'it', 'de', 'fr'] as $lang) {
                LookupItemTranslation::updateOrCreate(
                    [
                        'lookup_item_id' => $item->id,
                        'locale' => $lang,
                    ],
                    [
                        'label' => $crit['names'][$lang] ?? $crit['names']['en'],
                        'description' => "Weight: {$crit['weight']}% - Locale: {$lang}",
                    ]
                );
            }
        }
    }
}
