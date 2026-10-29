<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignTypeSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $types = [
            ['code' => 'email', 'name' => 'Email Marketing Sequence'],
            ['code' => 'webinar', 'name' => 'Executive ERP Webinar'],
            ['code' => 'conference', 'name' => 'Industry Expo & Summit'],
            ['code' => 'ppc', 'name' => 'Pay-Per-Click Search Campaign'],
            ['code' => 'social', 'name' => 'Paid Social Promotion'],
            ['code' => 'print', 'name' => 'Direct Mail & Industry Journal'],
            ['code' => 'partner', 'name' => 'Co-Marketing Partner Campaign'],
        ];

        foreach ($tenants as $tenant) {
            foreach ($types as $type) {
                DB::table('crm_campaign_types')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $type['code'],
                    ],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => $type['name'],
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
