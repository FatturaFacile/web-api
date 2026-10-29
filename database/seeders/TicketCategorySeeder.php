<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $categories = [
            [
                'code' => 'TCK_HARDWARE',
                'name' => 'Hardware & Equipment Defect',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Guasto Hardware e Apparecchiature',
                    'en' => 'Hardware & Equipment Defect',
                    'de' => 'Hardware- und Gerätefehler',
                    'fr' => 'Défaillance Matérielle et Équipement',
                ],
            ],
            [
                'code' => 'TCK_SOFTWARE',
                'name' => 'Software & Application Incident',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Incidente Software e Applicativo',
                    'en' => 'Software & Application Incident',
                    'de' => 'Software- und Anwendungsstörung',
                    'fr' => 'Incident Logiciel et Application',
                ],
            ],
            [
                'code' => 'TCK_ACCESS',
                'name' => 'Access & Security Permissions',
                'type' => 'customer_support',
                'translations' => [
                    'it' => 'Accessi e Permessi di Sicurezza',
                    'en' => 'Access & Security Permissions',
                    'de' => 'Zugriffs- und Sicherheitsberechtigungen',
                    'fr' => 'Accès et Autorisations de Sécurité',
                ],
            ],
            [
                'code' => 'TCK_FIELD_ONSITE',
                'name' => 'On-site Field Intervention Request',
                'type' => 'field_service',
                'translations' => [
                    'it' => 'Richiesta Intervento Tecnico sul Posto',
                    'en' => 'On-site Field Intervention Request',
                    'de' => 'Vor-Ort-Technikereinsatz',
                    'fr' => 'Demande d\'Intervention sur Site',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($categories as $cat) {
                $existingId = DB::table('esm_service_categories')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $cat['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('esm_service_categories')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $cat['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $cat['name'],
                        'description' => $cat['name'],
                        'is_active' => true,
                        'translations' => json_encode($cat['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
