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
                $exists = DB::table('crm_lead_sources')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $source['code'])
                    ->first();

                if (! $exists) {
                    DB::table('crm_lead_sources')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenant->id,
                        'code' => $source['code'],
                        'name' => $source['name'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('crm_lead_sources')->where('id', $exists->id)->update([
                        'name' => $source['name'],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
