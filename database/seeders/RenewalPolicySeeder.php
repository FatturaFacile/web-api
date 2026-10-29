<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RenewalPolicySeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            DB::table('sub_dunning_policies')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'policy_name' => 'Enterprise Standard Dunning & Renewal Policy'],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $tenant->id,
                    'is_default' => true,
                    'soft_reminder_days' => 3,
                    'formal_notice_days' => 10,
                    'suspension_days' => 25,
                    'termination_days' => 45,
                    'metadata' => json_encode([
                        'renewal_window_days' => 30,
                        'auto_renewal_enabled' => true,
                        'translations' => [
                            'it' => 'Policy standard di rinnovo e solleciti',
                            'en' => 'Standard renewal and dunning policy',
                            'de' => 'Standard Mahn- und Verlängerungsrichtlinie',
                            'fr' => 'Politique standard de renouvellement et relance',
                        ],
                    ]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
