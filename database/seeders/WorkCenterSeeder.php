<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkCenterSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $centers = [
                [
                    'work_center_code' => 'WC-MACHINING-01',
                    'name' => 'Centro Lavorazioni Meccaniche CNC / CNC Machining Center',
                    'description' => 'High speed 5-axis milling and turning center (DE: CNC-Bearbeitungszentrum; FR: Centre d\'usinage CNC)',
                    'department_code' => 'FABRICATION',
                    'capacity_hours_per_day' => 16.00,
                    'efficiency_rate' => 95.00,
                    'availability_rate' => 90.00,
                    'hourly_cost_rate' => 65.0000,
                    'hourly_overhead_rate' => 25.0000,
                    'status' => 'active',
                ],
                [
                    'work_center_code' => 'WC-WINDING-02',
                    'name' => 'Reparto Avvolgimento Motori / Motor Winding Cell',
                    'description' => 'Automated stator and coil winding line (DE: Wickelzentrum; FR: Atelier de bobinage)',
                    'department_code' => 'ELECTRICAL',
                    'capacity_hours_per_day' => 8.00,
                    'efficiency_rate' => 98.00,
                    'availability_rate' => 95.00,
                    'hourly_cost_rate' => 45.0000,
                    'hourly_overhead_rate' => 18.0000,
                    'status' => 'active',
                ],
                [
                    'work_center_code' => 'WC-ASSEMBLY-03',
                    'name' => 'Linea Assemblaggio Finale / Final Assembly Line',
                    'description' => 'Cleanroom assembly and testing bench (DE: Endmontage; FR: Assemblage final)',
                    'department_code' => 'ASSEMBLY',
                    'capacity_hours_per_day' => 8.00,
                    'efficiency_rate' => 92.00,
                    'availability_rate' => 96.00,
                    'hourly_cost_rate' => 38.0000,
                    'hourly_overhead_rate' => 15.0000,
                    'status' => 'active',
                ],
            ];

            foreach ($centers as $wc) {
                DB::table('mfg_work_centers')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'work_center_code' => $wc['work_center_code'],
                    ],
                    array_merge($wc, [
                        'id' => DB::raw("COALESCE((SELECT id FROM mfg_work_centers WHERE tenant_id = '{$tenant->id}' AND work_center_code = '{$wc['work_center_code']}'), '".Str::uuid7()."')"),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }
        }
    }
}
