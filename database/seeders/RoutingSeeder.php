<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoutingSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $pumpItem = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'FG-PUMP-HD500')->first();
            $machiningWc = DB::table('mfg_work_centers')->where('tenant_id', $tenant->id)->where('work_center_code', 'WC-MACHINING-01')->first();
            $assemblyWc = DB::table('mfg_work_centers')->where('tenant_id', $tenant->id)->where('work_center_code', 'WC-ASSEMBLY-03')->first();

            if (! $pumpItem || ! $machiningWc || ! $assemblyWc) {
                continue;
            }

            $existingRouting = DB::table('mfg_routings')
                ->where('tenant_id', $tenant->id)
                ->where('routing_number', 'RTG-PUMP-500')
                ->where('revision', 'A')
                ->first();
            $routingId = $existingRouting ? $existingRouting->id : (string) Str::uuid7();

            DB::table('mfg_routings')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'routing_number' => 'RTG-PUMP-500',
                    'revision' => 'A',
                ],
                [
                    'id' => $routingId,
                    'mfg_item_id' => $pumpItem->id,
                    'name' => 'Ciclo di Produzione Pompa HD500 / HD500 Manufacturing Routing (DE: Arbeitsplan; FR: Gamme de fabrication)',
                    'status' => 'active',
                    'is_default' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('mfg_operations')->where('routing_id', $routingId)->delete();
            DB::table('mfg_operations')->insert([
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'routing_id' => $routingId,
                    'sequence' => 10,
                    'operation_code' => 'OP-TURN-SHAFT',
                    'name' => 'Tornitura Albero Pompa / Shaft Turning Operation',
                    'work_center_id' => $machiningWc->id,
                    'setup_time_minutes' => 30.00,
                    'run_time_minutes_per_unit' => 15.00,
                    'labor_time_minutes_per_unit' => 15.00,
                    'standard_labor_rate' => 35.0000,
                    'standard_machine_rate' => 65.0000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'routing_id' => $routingId,
                    'sequence' => 20,
                    'operation_code' => 'OP-ASSY-TEST',
                    'name' => 'Assemblaggio e Collaudo Idraulico / Final Assembly & Pressure Test',
                    'work_center_id' => $assemblyWc->id,
                    'setup_time_minutes' => 15.00,
                    'run_time_minutes_per_unit' => 25.00,
                    'labor_time_minutes_per_unit' => 25.00,
                    'standard_labor_rate' => 28.0000,
                    'standard_machine_rate' => 35.0000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            DB::table('mfg_items')->where('id', $pumpItem->id)->update(['default_routing_id' => $routingId]);
        }
    }
}
