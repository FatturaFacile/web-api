<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectPhaseSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_phase_type',
                'name' => 'Standard Project Lifecycle Phases',
                'description' => 'Standard WBS phase designations across project lifecycle models',
                'items' => [
                    ['code' => 'initiation', 'sort' => 10, 'translations' => ['en' => 'Phase 1: Inception & Scoping', 'it' => 'Fase 1: Avvio & Definizione Ambito', 'de' => 'Phase 1: Initiierung & Scoping', 'fr' => 'Phase 1: Cadrage & Initialisation']],
                    ['code' => 'engineering', 'sort' => 20, 'translations' => ['en' => 'Phase 2: Detailed Engineering & Design', 'it' => 'Fase 2: Progettazione Esecutiva', 'de' => 'Phase 2: Detailplanung & Design', 'fr' => 'Phase 2: Conception & Ingénierie']],
                    ['code' => 'procurement', 'sort' => 30, 'translations' => ['en' => 'Phase 3: Sourcing & Pre-fabrication', 'it' => 'Fase 3: Approvvigionamento & Prefabbricazione', 'de' => 'Phase 3: Beschaffung & Vorfertigung', 'fr' => 'Phase 3: Achats & Préfabrication']],
                    ['code' => 'execution', 'sort' => 40, 'translations' => ['en' => 'Phase 4: Construction / Implementation', 'it' => 'Fase 4: Esecuzione Cantiere / Sviluppo', 'de' => 'Phase 4: Durchführung / Bau', 'fr' => 'Phase 4: Réalisation / Chantier']],
                    ['code' => 'commissioning', 'sort' => 50, 'translations' => ['en' => 'Phase 5: Commissioning & Acceptance Testing', 'it' => 'Fase 5: Collaudo & Messa in Servizio', 'de' => 'Phase 5: Inbetriebnahme & Abnahme', 'fr' => 'Phase 5: Recette & Mise en service']],
                    ['code' => 'closure', 'sort' => 60, 'translations' => ['en' => 'Phase 6: Warranty, Handover & Closure', 'it' => 'Fase 6: Consegna, Garanzia & Chiusura', 'de' => 'Phase 6: Übergabe & Projektabschluss', 'fr' => 'Phase 6: Clôture & Garantie']],
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
