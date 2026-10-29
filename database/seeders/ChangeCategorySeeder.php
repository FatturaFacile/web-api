<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChangeCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $categories = [
            [
                'code' => 'CHG_CAT_RELEASE',
                'name' => 'Major Software Release Deployment',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Rilascio Major Release Software',
                    'en' => 'Major Software Release Deployment',
                    'de' => 'Bereitstellung Hauptversion Software',
                    'fr' => 'Déploiement Version Majeure',
                ],
            ],
            [
                'code' => 'CHG_CAT_MAINT',
                'name' => 'Scheduled Network & Server Maintenance',
                'type' => 'maintenance',
                'translations' => [
                    'it' => 'Manutenzione Programmata Server e Rete',
                    'en' => 'Scheduled Network & Server Maintenance',
                    'de' => 'Geplante Server- und Netzwerkwartung',
                    'fr' => 'Maintenance Planifiée Serveurs et Réseau',
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($categories as $c) {
                $existingId = DB::table('esm_service_categories')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $c['code'])
                    ->value('id');
                $id = $existingId ?: (string) Str::uuid7();

                DB::table('esm_service_categories')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $c['code']],
                    [
                        'id' => $id,
                        'company_id' => $tenant->id,
                        'name' => $c['name'],
                        'description' => $c['name'],
                        'is_active' => true,
                        'translations' => json_encode($c['translations']),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
