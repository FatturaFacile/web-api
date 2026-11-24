<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProblemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $categories = [
            [
                'code' => 'PRB_CAT_CODE',
                'name' => 'Core Application Logic Defect',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Difetto Logica Applicativa Core',
                    'en' => 'Core Application Logic Defect',
                    'de' => 'Fehler in der Kernanwendungslogik',
                    'fr' => 'Défaut de Logique Applicative',
                ],
            ],
            [
                'code' => 'PRB_CAT_PERF',
                'name' => 'System Throughput & Memory Degradation',
                'type' => 'it_service',
                'translations' => [
                    'it' => 'Degrado Throughput e Memoria di Sistema',
                    'en' => 'System Throughput & Memory Degradation',
                    'de' => 'Systemdurchsatz- und Speicherabfall',
                    'fr' => 'Dégradation Débit et Mémoire Système',
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
