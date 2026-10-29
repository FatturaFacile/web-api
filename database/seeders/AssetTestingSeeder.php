<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssetTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first() ?: Tenant::factory()->create([
            'name' => 'EAM Testing Enterprise S.p.A.',
            'legal_name' => 'EAM Testing Enterprise S.p.A.',
        ]);

        app(TenantContext::class)->setTenant($tenant);

        $this->call(AssetReferenceSeeder::class);

        $existingLocId = DB::table('eam_asset_locations')
            ->where('tenant_id', $tenant->id)
            ->where('code', 'LOC-MILAN-HQ')
            ->value('id');

        DB::table('eam_asset_locations')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'code' => 'LOC-MILAN-HQ'],
            [
                'id' => $existingLocId ?: (string) Str::uuid7(),
                'name' => 'Milan Manufacturing Plant & HQ',
                'location_type' => 'facility',
                'address' => 'Via Mecenate 90',
                'city' => 'Milano',
                'country' => 'IT',
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
