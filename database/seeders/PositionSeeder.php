<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PositionSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $positions = [
            [
                'code' => 'POS_MFG_DIR',
                'dep_code' => 'DEP_MFG',
                'grade_code' => 'JG_L4',
                'title' => 'Plant & Manufacturing Director',
                'headcount_budget' => 2,
                'is_critical_role' => true,
                'translations' => [
                    'it' => 'Direttore di Stabilimento e Produzione',
                    'en' => 'Plant & Manufacturing Director',
                    'de' => 'Werks- und Fertigungsleiter',
                    'fr' => 'Directeur d\'Usine et de Production',
                ],
            ],
            [
                'code' => 'POS_CNC_LEAD',
                'dep_code' => 'DEP_MFG',
                'grade_code' => 'JG_L3',
                'title' => 'Lead CNC Machining Engineer',
                'headcount_budget' => 8,
                'is_critical_role' => false,
                'translations' => [
                    'it' => 'Ingegnere Capo Lavorazioni CNC',
                    'en' => 'Lead CNC Machining Engineer',
                    'de' => 'Leitender CNC-Fertigungsingenieur',
                    'fr' => 'Ingénieur Principal Usinage CNC',
                ],
            ],
            [
                'code' => 'POS_CONST_MGR',
                'dep_code' => 'DEP_CONST',
                'grade_code' => 'JG_L4',
                'title' => 'Chief Site & Construction Manager',
                'headcount_budget' => 4,
                'is_critical_role' => true,
                'translations' => [
                    'it' => 'Direttore Tecnico di Cantiere',
                    'en' => 'Chief Site & Construction Manager',
                    'de' => 'Oberbauleiter',
                    'fr' => 'Directeur Technique de Chantier',
                ],
            ],
            [
                'code' => 'POS_PRO_ARCH',
                'dep_code' => 'DEP_PRO',
                'grade_code' => 'JG_L4',
                'title' => 'Principal Enterprise Architect',
                'headcount_budget' => 5,
                'is_critical_role' => true,
                'translations' => [
                    'it' => 'Principal Enterprise Architect',
                    'en' => 'Principal Enterprise Architect',
                    'de' => 'Principal Unternehmensarchitekt',
                    'fr' => 'Architecte d\'Entreprise Principal',
                ],
            ],
            [
                'code' => 'POS_RET_MGR',
                'dep_code' => 'DEP_RET',
                'grade_code' => 'JG_L3',
                'title' => 'Flagship Store Operations Manager',
                'headcount_budget' => 6,
                'is_critical_role' => false,
                'translations' => [
                    'it' => 'Store Manager Flagship',
                    'en' => 'Flagship Store Operations Manager',
                    'de' => 'Flagship-Store-Filialleiter',
                    'fr' => 'Directeur de Magasin Phare',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($positions as $pos) {
                $depId = DB::table('hr_departments')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $pos['dep_code'])
                    ->value('id');

                if (! $depId) {
                    continue;
                }

                $gradeId = DB::table('hr_job_grades')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $pos['grade_code'])
                    ->value('id');

                $existingId = DB::table('hr_positions')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $pos['code'])
                    ->value('id');

                DB::table('hr_positions')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $pos['code']],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'department_id' => $depId,
                        'job_grade_id' => $gradeId,
                        'title' => $pos['title'],
                        'headcount_budget' => $pos['headcount_budget'],
                        'is_critical_role' => $pos['is_critical_role'],
                        'is_active' => true,
                        'translations' => json_encode($pos['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
