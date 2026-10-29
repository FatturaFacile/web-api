<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $sources = [
            ['code' => 'web', 'name' => 'Inbound Website Form'],
            ['code' => 'referral', 'name' => 'Customer / Partner Referral'],
            ['code' => 'outbound', 'name' => 'Outbound SDR Campaign'],
            ['code' => 'organic_search', 'name' => 'Organic Search / SEO'],
            ['code' => 'event', 'name' => 'Conference & Industry Trade Show'],
            ['code' => 'partner', 'name' => 'Certified ERP Reseller Partner'],
            ['code' => 'advertising', 'name' => 'Digital Advertising (PPC/Paid)'],
            ['code' => 'social', 'name' => 'LinkedIn Social Selling'],
        ];

        foreach ($tenants as $tenant) {
            foreach ($sources as $source) {
                DB::table('crm_lead_sources')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $source['code'],
                    ],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => $source['name'],
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
