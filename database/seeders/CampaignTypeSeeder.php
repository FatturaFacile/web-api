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
            ['code' => 'email', 'name' => 'Email Marketing Campaign'],
            ['code' => 'webinar', 'name' => 'Webinar / Online Demo'],
            ['code' => 'trade_show', 'name' => 'Trade Show / Industry Expo'],
            ['code' => 'paid_ads', 'name' => 'Digital Advertising (PPC/Display)'],
            ['code' => 'content', 'name' => 'Content Marketing & Whitepapers'],
            ['code' => 'partner_co_marketing', 'name' => 'Partner Co-Marketing Campaign'],
        ];

        foreach ($tenants as $tenant) {
            foreach ($types as $type) {
                $exists = DB::table('crm_campaign_types')
                    ->where('tenant_id', $tenant->id)
                    ->where('code', $type['code'])
                    ->first();

                if (! $exists) {
                    DB::table('crm_campaign_types')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenant->id,
                        'code' => $type['code'],
                        'name' => $type['name'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('crm_campaign_types')->where('id', $exists->id)->update([
                        'name' => $type['name'],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
