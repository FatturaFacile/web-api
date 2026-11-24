<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CertificationSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $courses = [
            [
                'course_code' => 'TRN_ISO_45001',
                'title' => 'ISO 45001 Occupational Health & Safety Certification Course',
                'category' => 'safety',
                'provider' => 'TÜV Rheinland Academy',
                'duration_hours' => 24.00,
                'cost_per_participant' => 850.00,
                'is_mandatory_compliance' => true,
                'translations' => [
                    'it' => 'Corso Certificazione Sicurezza sul Lavoro ISO 45001',
                    'en' => 'ISO 45001 Occupational Health & Safety Certification Course',
                    'de' => 'ISO 45001 Arbeitsschutz-Zertifizierungskurs',
                    'fr' => 'Cours de Certification Santé et Sécurité ISO 45001',
                ],
            ],
            [
                'course_code' => 'TRN_PMP_BOOTCAMP',
                'title' => 'PMI Project Management Professional (PMP) Executive Prep',
                'category' => 'leadership',
                'provider' => 'PMI Chapter Europe',
                'duration_hours' => 35.00,
                'cost_per_participant' => 1450.00,
                'is_mandatory_compliance' => false,
                'translations' => [
                    'it' => 'Preparazione Esecutiva PMP Project Management',
                    'en' => 'PMI Project Management Professional (PMP) Executive Prep',
                    'de' => 'PMI Project Management Professional (PMP) Vorbereitung',
                    'fr' => 'Préparation Exécutive PMP Gestion de Projet',
                ],
            ],
            [
                'course_code' => 'TRN_SIEMENS_CNC',
                'title' => 'Siemens SINUMERIK 840D 5-Axis Masterclass',
                'category' => 'technical',
                'provider' => 'Siemens Industrial Training Center',
                'duration_hours' => 40.00,
                'cost_per_participant' => 1900.00,
                'is_mandatory_compliance' => false,
                'translations' => [
                    'it' => 'Masterclass Siemens SINUMERIK 840D 5 Assi',
                    'en' => 'Siemens SINUMERIK 840D 5-Axis Masterclass',
                    'de' => 'Siemens SINUMERIK 840D 5-Achsen-Meisterkurs',
                    'fr' => 'Masterclass Siemens SINUMERIK 840D 5 Axes',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($courses as $course) {
                $existingId = DB::table('hr_training_courses')
                    ->where('tenant_id', $tenant->id)
                    ->where('course_code', $course['course_code'])
                    ->value('id');

                DB::table('hr_training_courses')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'course_code' => $course['course_code']],
                    [
                        'id' => $existingId ?: (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'title' => $course['title'],
                        'category' => $course['category'],
                        'provider' => $course['provider'],
                        'duration_hours' => $course['duration_hours'],
                        'cost_per_participant' => $course['cost_per_participant'],
                        'currency' => 'EUR',
                        'validity_months' => 24,
                        'is_mandatory_compliance' => $course['is_mandatory_compliance'],
                        'is_active' => true,
                        'translations' => json_encode($course['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
