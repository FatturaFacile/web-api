<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_expense_category',
                'name' => 'Project Expense Categories',
                'description' => 'Classification of project incidental and direct expenses',
                'items' => [
                    ['code' => 'travel_lodging', 'sort' => 10, 'translations' => ['en' => 'Travel & Lodging', 'it' => 'Viaggio & Alloggio', 'de' => 'Reise & Unterkunft', 'fr' => 'Déplacement & Hébergement']],
                    ['code' => 'meals_subsistence', 'sort' => 20, 'translations' => ['en' => 'Meals & Subsistence', 'it' => 'Vitto & Rimborsi Spese', 'de' => 'Verpflegung & Spesen', 'fr' => 'Repas & Indemnités']],
                    ['code' => 'licensing_permits', 'sort' => 30, 'translations' => ['en' => 'Permits & Municipal Licenses', 'it' => 'Permessi & Oneri Comunali', 'de' => 'Genehmigungen & Gebühren', 'fr' => 'Permis & Redevances']],
                    ['code' => 'site_rentals', 'sort' => 40, 'translations' => ['en' => 'Temporary Site Equipment Rentals', 'it' => 'Noleggi Attrezzature Cantiere', 'de' => 'Baustellenmietgeräte', 'fr' => 'Location Matériel de Chantier']],
                    ['code' => 'laboratory_testing', 'sort' => 50, 'translations' => ['en' => 'Quality Certifications & Testing', 'it' => 'Certificazioni & Collaudi', 'de' => 'Qualitätsprüfung & Zertifizierung', 'fr' => 'Essais & Contrôles Qualité']],
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
