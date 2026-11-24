<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RevenueRecognitionSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $rules = [
                ['code' => 'IFRS15-STRAIGHT-LINE', 'name_it' => 'Riconoscimento Lineare Mensile (Canone)', 'name_en' => 'Straight-Line Monthly Recognition', 'method' => 'straight_line_over_time'],
                ['code' => 'IFRS15-POINT-IN-TIME', 'name_it' => 'Riconoscimento Puntuale (Setup / Hardware)', 'name_en' => 'Point in Time Recognition', 'method' => 'point_in_time'],
                ['code' => 'IFRS15-USAGE-BASED', 'name_it' => 'Riconoscimento a Consumo Effettivo', 'name_en' => 'Usage / Consumption Recognition', 'method' => 'usage_based'],
            ];

            foreach ($rules as $r) {
                DB::table('sub_price_books')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'code' => $r['code']],
                    [
                        'id' => (string) Str::uuid7(),
                        'company_id' => $tenant->id,
                        'name' => $r['name_it'],
                        'currency' => 'EUR',
                        'is_default' => false,
                        'is_active' => true,
                        'rate_overrides' => json_encode(['method' => $r['method'], 'name_en' => $r['name_en']]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
