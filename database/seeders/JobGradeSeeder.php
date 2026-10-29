<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class JobGradeSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $grades = [
            [
                'code' => 'JG_L1',
                'name' => 'Junior Associate / Specialist I',
                'level_rank' => 1,
                'min_salary' => 26000.00,
                'midpoint_salary' => 31000.00,
                'max_salary' => 36000.00,
                'translations' => [
                    'it' => 'Specialista Junior I',
                    'en' => 'Junior Associate / Specialist I',
                    'de' => 'Junior Fachkraft I',
                    'fr' => 'Spécialiste Junior I',
                ],
            ],
            [
                'code' => 'JG_L2',
                'name' => 'Intermediate Specialist / Technician II',
                'level_rank' => 2,
                'min_salary' => 34000.00,
                'midpoint_salary' => 42000.00,
                'max_salary' => 50000.00,
                'translations' => [
                    'it' => 'Tecnico / Specialista Intermedio II',
                    'en' => 'Intermediate Specialist / Technician II',
                    'de' => 'Fachkraft / Techniker II',
                    'fr' => 'Technicien / Spécialiste Intermédiaire II',
                ],
            ],
            [
                'code' => 'JG_L3',
                'name' => 'Senior Engineer / Consultant III',
                'level_rank' => 3,
                'min_salary' => 48000.00,
                'midpoint_salary' => 60000.00,
                'max_salary' => 74000.00,
                'translations' => [
                    'it' => 'Ingegnere Senior / Consulente III',
                    'en' => 'Senior Engineer / Consultant III',
                    'de' => 'Senior Ingenieur / Berater III',
                    'fr' => 'Ingénieur Senior / Consultant III',
                ],
            ],
            [
                'code' => 'JG_L4',
                'name' => 'Department Manager / Principal Lead IV',
                'level_rank' => 4,
                'min_salary' => 70000.00,
                'midpoint_salary' => 88000.00,
                'max_salary' => 110000.00,
                'translations' => [
                    'it' => 'Responsabile di Dipartimento / Principal IV',
                    'en' => 'Department Manager / Principal Lead IV',
                    'de' => 'Abteilungsleiter / Principal Lead IV',
                    'fr' => 'Directeur de Département / Principal IV',
                ],
            ],
            [
                'code' => 'JG_L5',
                'name' => 'Executive Director / VP V',
                'level_rank' => 5,
                'min_salary' => 105000.00,
                'midpoint_salary' => 135000.00,
                'max_salary' => 175000.00,
                'translations' => [
                    'it' => 'Direttore Esecutivo / Vicepresidente V',
                    'en' => 'Executive Director / VP V',
                    'de' => 'Geschäftsführender Direktor / VP V',
                    'fr' => 'Directeur Exécutif / Vice-Président V',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($grades as $grade) {
                $existingId = DB::table('hr_job_grades')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $grade['code'])
                    ->value('id');

                DB::table('hr_job_grades')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $grade['code']],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $grade['name'],
                        'level_rank' => $grade['level_rank'],
                        'min_salary' => $grade['min_salary'],
                        'midpoint_salary' => $grade['midpoint_salary'],
                        'max_salary' => $grade['max_salary'],
                        'currency' => 'EUR',
                        'is_active' => true,
                        'translations' => json_encode($grade['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
