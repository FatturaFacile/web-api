<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MachineSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $machiningWc = DB::table('mfg_work_centers')->where('tenant_id', $tenant->id)->where('work_center_code', 'WC-MACHINING-01')->first();
            if (! $machiningWc) {
                continue;
            }

            $machines = [
                [
                    'machine_code' => 'MC-DMG-5AXIS',
                    'name' => 'Torno-Fresatrice DMG Mori 5 Assi / DMG Mori 5-Axis Mill-Turn',
                    'work_center_id' => $machiningWc->id,
                    'hourly_rate' => 85.0000,
                    'power_kw' => 35.00,
                    'status' => 'active',
                ],
                [
                    'machine_code' => 'MC-MAZAK-LATHE',
                    'name' => 'Tornio CNC Mazak Quick Turn / Mazak CNC Lathe',
                    'work_center_id' => $machiningWc->id,
                    'hourly_rate' => 60.0000,
                    'power_kw' => 22.00,
                    'status' => 'active',
                ],
            ];

            foreach ($machines as $m) {
                DB::table('mfg_machine_centers')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'machine_code' => $m['machine_code'],
                    ],
                    array_merge($m, [
                        'id' => DB::raw("COALESCE((SELECT id FROM mfg_machine_centers WHERE tenant_id = '{$tenant->id}' AND machine_code = '{$m['machine_code']}'), '".Str::uuid7()."')"),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
            }
        }
    }
}
