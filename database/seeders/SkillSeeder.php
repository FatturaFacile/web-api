<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $skills = [
            [
                'code' => 'SK_CNC_5AXIS',
                'name' => '5-Axis CNC Programming & CAD/CAM',
                'category' => 'technical',
                'translations' => [
                    'it' => 'Programmazione CNC 5 Assi e CAD/CAM',
                    'en' => '5-Axis CNC Programming & CAD/CAM',
                    'de' => '5-Achsen-CNC-Programmierung & CAD/CAM',
                    'fr' => 'Programmation CNC 5 Axes et CAO/FAO',
                ],
            ],
            [
                'code' => 'SK_BIM_CIVIL',
                'name' => 'BIM Structural Modeling & Site Safety',
                'category' => 'technical',
                'translations' => [
                    'it' => 'Modellazione Strutturale BIM e Sicurezza Cantieri',
                    'en' => 'BIM Structural Modeling & Site Safety',
                    'de' => 'BIM-Tragwerksmodellierung & Baustellensicherheit',
                    'fr' => 'Modélisation Structurelle BIM et Sécurité Chantier',
                ],
            ],
            [
                'code' => 'SK_ERP_ARCH',
                'name' => 'Enterprise ERP & Cloud Architecture',
                'category' => 'technical',
                'translations' => [
                    'it' => 'Architettura ERP Enterprise e Cloud',
                    'en' => 'Enterprise ERP & Cloud Architecture',
                    'de' => 'Enterprise-ERP- & Cloud-Architektur',
                    'fr' => 'Architecture ERP d\'Entreprise et Cloud',
                ],
            ],
            [
                'code' => 'SK_OMNICHANNEL',
                'name' => 'Omnichannel Retail & POS Operations',
                'category' => 'operational',
                'translations' => [
                    'it' => 'Operazioni Retail Omnicanale e POS',
                    'en' => 'Omnichannel Retail & POS Operations',
                    'de' => 'Omnichannel-Einzelhandel & POS-Betrieb',
                    'fr' => 'Opérations Retail Omnicanal et POS',
                ],
            ],
            [
                'code' => 'SK_EXEC_LEAD',
                'name' => 'Cross-Functional Executive Leadership',
                'category' => 'leadership',
                'translations' => [
                    'it' => 'Leadership Esecutiva Interfunzionale',
                    'en' => 'Cross-Functional Executive Leadership',
                    'de' => 'Funktionsübergreifende Führungskompetenz',
                    'fr' => 'Leadership Exécutif Interfonctionnel',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($skills as $skill) {
                $existingId = DB::table('hr_skills')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $skill['code'])
                    ->value('id');

                DB::table('hr_skills')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $skill['code']],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $skill['name'],
                        'category' => $skill['category'],
                        'is_active' => true,
                        'translations' => json_encode($skill['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
