<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $categories = [
            [
                'code' => 'SVC_CAT_FIELD',
                'name' => 'Industrial Field Engineering',
                'type' => 'field_service',
                'translations' => [
                    'it' => 'Ingegneria di Campo Industriale',
                    'en' => 'Industrial Field Engineering',
                    'de' => 'Industrieller Außendienst',
                    'fr' => 'Ingénierie de Terrain Industrielle',
                ],
            ],
            [
                'code' => 'SVC_CAT_PREV_MAINT',
                'name' => 'Preventive Facility Maintenance',
                'type' => 'maintenance',
                'translations' => [
                    'it' => 'Manutenzione Preventiva Impianti',
                    'en' => 'Preventive Facility Maintenance',
                    'de' => 'Präventive Anlagenwartung',
                    'fr' => 'Maintenance Préventive des Installations',
                ],
            ],
            [
                'code' => 'SVC_CAT_CORR_MAINT',
                'name' => 'Emergency Breakdown Maintenance',
                'type' => 'maintenance',
                'translations' => [
                    'it' => 'Manutenzione Correttiva e Riparazione Guasti',
                    'en' => 'Emergency Breakdown Maintenance',
                    'de' => 'Notfallinstandsetzung und Reparatur',
                    'fr' => 'Maintenance Corrective d\'Urgence',
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
