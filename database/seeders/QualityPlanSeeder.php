<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class QualityPlanSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $tenants = collect([Tenant::factory()->create()]);
        }

        foreach ($tenants as $tenant) {
            $pumpItem = DB::table('mfg_items')->where('tenant_id', $tenant->id)->where('item_code', 'FG-PUMP-HD500')->first();
            if (! $pumpItem) {
                continue;
            }

            $existingPlan = DB::table('mfg_quality_plans')
                ->where('tenant_id', $tenant->id)
                ->where('plan_code', 'QP-PUMP-FINAL')
                ->first();
            $planId = $existingPlan ? $existingPlan->id : (string) Str::uuid7();

            DB::table('mfg_quality_plans')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                    'plan_code' => 'QP-PUMP-FINAL',
                ],
                [
                    'id' => $planId,
                    'name' => 'Piano Controllo Qualità Finale Pompa / Final Pump Quality Plan (DE: Endprüfplan; FR: Plan de contrôle final)',
                    'inspection_type' => 'final',
                    'mfg_item_id' => $pumpItem->id,
                    'description' => 'ISO 9001 hydrostatic and dimensional compliance protocol',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            DB::table('mfg_quality_characteristics')->where('quality_plan_id', $planId)->delete();
            DB::table('mfg_quality_characteristics')->insert([
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'quality_plan_id' => $planId,
                    'characteristic_name' => 'Pressione di Tenuta Idraulica / Hydraulic Pressure Hold',
                    'target_value' => 350.0000,
                    'min_tolerance' => 340.0000,
                    'max_tolerance' => 360.0000,
                    'uom' => 'BAR',
                    'inspection_method' => 'Pressure Transducer Log',
                    'is_critical' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'quality_plan_id' => $planId,
                    'characteristic_name' => 'Diametro Albero / Shaft Concentricity Diameter',
                    'target_value' => 40.0000,
                    'min_tolerance' => 39.9800,
                    'max_tolerance' => 40.0200,
                    'uom' => 'MM',
                    'inspection_method' => 'Digital Micrometer',
                    'is_critical' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }
}
