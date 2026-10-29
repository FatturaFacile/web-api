<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $leaveTypes = [
            [
                'code' => 'ANNUAL_LEAVE',
                'name' => 'Paid Annual Vacation (Ferie)',
                'category' => 'vacation',
                'is_paid' => true,
                'annual_entitlement_hours' => 208.00,
                'accrual_frequency' => 'monthly',
                'max_carryover_hours' => 80.00,
                'requires_approval' => true,
                'translations' => [
                    'it' => 'Ferie Annuali Retribuite',
                    'en' => 'Paid Annual Vacation (Ferie)',
                    'de' => 'Bezahlter Jahresurlaub',
                    'fr' => 'Congés Payés Annuels',
                ],
            ],
            [
                'code' => 'ROL_PERMITS',
                'name' => 'Paid Working Time Reduction (ROL / Ex-Festività)',
                'category' => 'rol',
                'is_paid' => true,
                'annual_entitlement_hours' => 72.00,
                'accrual_frequency' => 'monthly',
                'max_carryover_hours' => 40.00,
                'requires_approval' => true,
                'translations' => [
                    'it' => 'Permessi Retribuiti ROL ed Ex-Festività',
                    'en' => 'Paid Working Time Reduction (ROL / Ex-Festività)',
                    'de' => 'Bezahlte Arbeitszeitverkürzung (ROL)',
                    'fr' => 'Réduction du Temps de Travail Payée (RTT / ROL)',
                ],
            ],
            [
                'code' => 'SICK_LEAVE',
                'name' => 'Medical & Sick Leave (Malattia)',
                'category' => 'sick',
                'is_paid' => true,
                'annual_entitlement_hours' => 0.00,
                'accrual_frequency' => 'none',
                'max_carryover_hours' => 0.00,
                'requires_approval' => false,
                'translations' => [
                    'it' => 'Congedo per Malattia',
                    'en' => 'Medical & Sick Leave (Malattia)',
                    'de' => 'Krankheitsurlaub',
                    'fr' => 'Congé de Maladie',
                ],
            ],
            [
                'code' => 'PARENTAL_LEAVE',
                'name' => 'Statutory Parental & Maternity/Paternity Leave',
                'category' => 'maternity',
                'is_paid' => true,
                'annual_entitlement_hours' => 0.00,
                'accrual_frequency' => 'none',
                'max_carryover_hours' => 0.00,
                'requires_approval' => true,
                'translations' => [
                    'it' => 'Congedo Parentale, Maternità e Paternità',
                    'en' => 'Statutory Parental & Maternity/Paternity Leave',
                    'de' => 'Elternzeit sowie Mutterschutz- und Vaterschaftsurlaub',
                    'fr' => 'Congé Parental, de Maternité et de Paternité',
                ],
            ],
            [
                'code' => 'STUDY_LEAVE',
                'name' => 'Professional Study & Exam Leave (150 Ore)',
                'category' => 'study',
                'is_paid' => true,
                'annual_entitlement_hours' => 48.00,
                'accrual_frequency' => 'yearly',
                'max_carryover_hours' => 0.00,
                'requires_approval' => true,
                'translations' => [
                    'it' => 'Permessi Studio e Diritto allo Studio (150 Ore)',
                    'en' => 'Professional Study & Exam Leave (150 Ore)',
                    'de' => 'Bildungsurlaub und Prüfungsfreistellung',
                    'fr' => 'Congé de Formation et d\'Examen',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($leaveTypes as $lt) {
                $existingId = DB::table('hr_leave_types')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $lt['code'])
                    ->value('id');

                DB::table('hr_leave_types')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $lt['code']],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $lt['name'],
                        'category' => $lt['category'],
                        'is_paid' => $lt['is_paid'],
                        'requires_approval' => $lt['requires_approval'],
                        'annual_entitlement_hours' => $lt['annual_entitlement_hours'],
                        'accrual_frequency' => $lt['accrual_frequency'],
                        'max_carryover_hours' => $lt['max_carryover_hours'],
                        'is_active' => true,
                        'translations' => json_encode($lt['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
