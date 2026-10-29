<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OpportunityStageSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        $stages = [
            ['code' => 'discovery', 'name' => 'Discovery & Requirements', 'sequence' => 10, 'probability' => 15.00, 'is_won' => false, 'is_lost' => false, 'stale_days' => 21],
            ['code' => 'qualification', 'name' => 'Technical & Commercial Qualification', 'sequence' => 20, 'probability' => 30.00, 'is_won' => false, 'is_lost' => false, 'stale_days' => 21],
            ['code' => 'proposal', 'name' => 'Proposal & Demonstration', 'sequence' => 30, 'probability' => 60.00, 'is_won' => false, 'is_lost' => false, 'stale_days' => 30],
            ['code' => 'negotiation', 'name' => 'Contract & Pricing Negotiation', 'sequence' => 40, 'probability' => 85.00, 'is_won' => false, 'is_lost' => false, 'stale_days' => 15],
            ['code' => 'closed_won', 'name' => 'Closed Won (Contract Signed)', 'sequence' => 50, 'probability' => 100.00, 'is_won' => true, 'is_lost' => false, 'stale_days' => 0],
            ['code' => 'closed_lost', 'name' => 'Closed Lost', 'sequence' => 60, 'probability' => 0.00, 'is_won' => false, 'is_lost' => true, 'stale_days' => 0],
        ];

        foreach ($tenants as $tenant) {
            foreach ($stages as $stage) {
                DB::table('crm_opportunity_stages')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'code' => $stage['code'],
                    ],
                    [
                        'id' => (string) Str::uuid7(),
                        'name' => $stage['name'],
                        'sequence' => $stage['sequence'],
                        'default_probability' => $stage['probability'],
                        'is_won' => $stage['is_won'],
                        'is_lost' => $stage['is_lost'],
                        'stale_days' => $stage['stale_days'],
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
