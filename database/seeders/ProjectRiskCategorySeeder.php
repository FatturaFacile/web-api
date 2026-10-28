<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectRiskCategorySeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_risk_category',
                'name' => 'Project Risk Categories',
                'description' => 'Classification of project risks per PMBOK / ISO 31000 standards',
                'items' => [
                    ['code' => 'technical', 'sort' => 10, 'translations' => ['en' => 'Technical & Engineering Complexity', 'it' => 'Tecnico & Complessità Ingegneristica', 'de' => 'Technik & Komplexität', 'fr' => 'Technique & Ingénierie']],
                    ['code' => 'commercial', 'sort' => 20, 'translations' => ['en' => 'Commercial & Contractual', 'it' => 'Commerciale & Contrattuale', 'de' => 'Kaufmännisch & Vertraglich', 'fr' => 'Commercial & Contractuel']],
                    ['code' => 'schedule', 'sort' => 30, 'translations' => ['en' => 'Schedule & Lead Times', 'it' => 'Pianificazione & Tempi di Consegna', 'de' => 'Terminplan & Durchlaufzeiten', 'fr' => 'Délais & Planning']],
                    ['code' => 'supply_chain', 'sort' => 40, 'translations' => ['en' => 'Supply Chain & Subcontractors', 'it' => 'Filiera & Subfornitori', 'de' => 'Lieferkette & Subunternehmer', 'fr' => 'Chaîne logistique & Sous-traitants']],
                    ['code' => 'regulatory', 'sort' => 50, 'translations' => ['en' => 'Regulatory & Health/Safety', 'it' => 'Normativo & Sicurezza sul Lavoro', 'de' => 'Regulatorisch & Arbeitssicherheit', 'fr' => 'Réglementaire & HSE']],
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
