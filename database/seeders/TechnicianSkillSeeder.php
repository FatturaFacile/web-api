<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TechnicianSkillSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $teams = [
            [
                'code' => 'TEAM_TIER1_DESK',
                'name' => 'Tier-1 Service Desk',
                'team_type' => 'helpdesk',
                'translations' => [
                    'it' => 'Service Desk di Primo Livello',
                    'en' => 'Tier-1 Service Desk',
                    'de' => 'Tier-1-Service-Desk',
                    'fr' => 'Centre de Services Niveau 1',
                ],
            ],
            [
                'code' => 'TEAM_FIELD_ENG',
                'name' => 'Mobile Field Technicians',
                'team_type' => 'field_service',
                'translations' => [
                    'it' => 'Tecnici Mobili sul Campo',
                    'en' => 'Mobile Field Technicians',
                    'de' => 'Mobile Servicetechniker',
                    'fr' => 'Techniciens Itinérants sur le Terrain',
                ],
            ],
            [
                'code' => 'TEAM_NET_OPS',
                'name' => 'Network & Cloud Operations Center',
                'team_type' => 'network_ops',
                'translations' => [
                    'it' => 'Centro Operativo Rete e Cloud',
                    'en' => 'Network & Cloud Operations Center',
                    'de' => 'Netzwerk- und Cloud-Operations-Center',
                    'fr' => 'Centre d\'Exploitation Réseau et Cloud',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($teams as $t) {
                $existingId = DB::table('esm_service_teams')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $t['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('esm_service_teams')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $t['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $t['name'],
                        'is_active' => true,
                        'translations' => json_encode($t['translations']),
                        'metadata' => json_encode(['team_type' => $t['team_type']]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
