<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectForecastCategorySeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_forecast_category',
                'name' => 'Project Financial Forecast Dimensions',
                'description' => 'Classification of cash and cost forecast line categories',
                'items' => [
                    ['code' => 'cash_inflow_milestone', 'sort' => 10, 'translations' => ['en' => 'Milestone Inflow', 'it' => 'Incasso da Milestone', 'de' => 'Meilenstein-Zufluss', 'fr' => 'Encaissement par Jalon']],
                    ['code' => 'labour_etc_outflow', 'sort' => 20, 'translations' => ['en' => 'Labour ETC Outflow', 'it' => 'Uscite Manodopera a Finire (ETC)', 'de' => 'Arbeitskosten-Restaufwand (ETC)', 'fr' => 'Décaissements Main-d\'œuvre (ETC)']],
                    ['code' => 'material_po_outflow', 'sort' => 30, 'translations' => ['en' => 'Material Procurement Outflow', 'it' => 'Uscite Acquisti Materiali', 'de' => 'Materialbeschaffungsabfluss', 'fr' => 'Achats Matériaux & Approvisionnements']],
                    ['code' => 'subcontractor_outflow', 'sort' => 40, 'translations' => ['en' => 'Subcontractor / Supplier Outflow', 'it' => 'Uscite Subappalti e Servizi', 'de' => 'Subunternehmerabfluss', 'fr' => 'Règlements Sous-traitants']],
                    ['code' => 'contingency_reserve', 'sort' => 50, 'translations' => ['en' => 'Contingency Reserve Drawdown', 'it' => 'Utilizzo Riserva Imprevisti', 'de' => 'Rückstellungsentnahme', 'fr' => 'Consommation Réserve pour Imprévus']],
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
                        'is_default' => false,
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
