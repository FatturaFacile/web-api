<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectMilestoneSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_milestone_type',
                'name' => 'Project Milestone Gateways',
                'description' => 'Contractual and delivery milestone checkpoint definitions',
                'items' => [
                    ['code' => 'kickoff', 'sort' => 10, 'translations' => ['en' => 'Formal Project Kickoff', 'it' => 'Avvio Lavori (Kickoff)', 'de' => 'Projekt-Kickoff', 'fr' => 'Lancement de Projet']],
                    ['code' => 'design_freeze', 'sort' => 20, 'translations' => ['en' => 'Design & Engineering Freeze', 'it' => 'Approvazione Progetto Esecutivo', 'de' => 'Planungsfreigabe', 'fr' => 'Validation des Plans']],
                    ['code' => 'civil_complete', 'sort' => 30, 'translations' => ['en' => 'Civil / Base Infrastructure Completion', 'it' => 'Completamento Opere Civili', 'de' => 'Rohbaufertigstellung', 'fr' => 'Fin du Gros Œuvre']],
                    ['code' => 'installation_complete', 'sort' => 40, 'translations' => ['en' => 'Systems Mechanical/Electrical Installation', 'it' => 'Fine Montaggi Impianti', 'de' => 'Montageabschluss', 'fr' => 'Fin des Installations']],
                    ['code' => 'fat_sat_pass', 'sort' => 50, 'translations' => ['en' => 'Factory & Site Acceptance Test (FAT/SAT)', 'it' => 'Superamento Collaudi (FAT/SAT)', 'de' => 'Erfolgreiche Werks-/Vor-Ort-Abnahme', 'fr' => 'Succès Recette Usine/Site']],
                    ['code' => 'commercial_handover', 'sort' => 60, 'translations' => ['en' => 'Commercial Handover & Final Settlement', 'it' => 'Consegna Commerciale & Saldo Finale', 'de' => 'Kaufmännische Übergabe', 'fr' => 'Réception Finale & Solde']],
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
