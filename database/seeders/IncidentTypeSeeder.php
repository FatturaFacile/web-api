<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $types = [
            [
                'code' => 'INC_CAT_INFRA',
                'name' => 'Cloud & Infrastructure Downtime',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Downtime Infrastruttura Cloud',
                    'en' => 'Cloud & Infrastructure Downtime',
                    'de' => 'Cloud- und Infrastrukturausfall',
                    'fr' => 'Interruption Infrastructure Cloud',
                ],
            ],
            [
                'code' => 'INC_CAT_SEC',
                'name' => 'Security & Breach Incident',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Incidente di Sicurezza o Violazione',
                    'en' => 'Security & Breach Incident',
                    'de' => 'Sicherheits- und Datenschutzvorfall',
                    'fr' => 'Incident de Sécurité ou Violation',
                ],
            ],
            [
                'code' => 'INC_CAT_DATA',
                'name' => 'Database Corruption or Loss',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Corruzione o Perdita Dati Database',
                    'en' => 'Database Corruption or Loss',
                    'de' => 'Datenbankbeschädigung oder Datenverlust',
                    'fr' => 'Corruption ou Perte de Données',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($types as $t) {
                $existingId = DB::table('esm_service_categories')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $t['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('esm_service_categories')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $t['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $t['name'],
                        'description' => $t['name'],
                        'is_active' => true,
                        'translations' => json_encode($t['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
