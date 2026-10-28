<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CustomerGroupSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        if (! $tenantId) {
            return;
        }

        $groups = [
            [
                'code' => 'RETAIL',
                'name' => 'Clienti al Dettaglio / Retail Customers / Einzelhandel / Clients de détail',
                'description' => 'Standard consumer and retail pricing',
                'discount_percentage' => 0.00,
            ],
            [
                'code' => 'WHOLESALE',
                'name' => 'Grossisti e Distributori / Wholesale & Distributors / Großhändler / Grossistes',
                'description' => 'Commercial wholesale pricing tier',
                'discount_percentage' => 15.00,
            ],
            [
                'code' => 'ENTERPRISE',
                'name' => 'Grandi Aziende / Enterprise Key Accounts / Großkunden / Grands Comptes',
                'description' => 'Strategic corporate accounts with negotiated volume discounts',
                'discount_percentage' => 25.00,
            ],
            [
                'code' => 'GOVERNMENT',
                'name' => 'Pubblica Amministrazione / Public Administration / Öffentliche Hand / Administration Publique',
                'description' => 'Public sector institutional clients with split-payment mandate',
                'discount_percentage' => 10.00,
            ],
        ];

        foreach ($groups as $group) {
            $existing = DB::table('customer_groups')
                ->where('tenant_id', $tenantId)
                ->where('code', $group['code'])
                ->first();

            if (! $existing) {
                DB::table('customer_groups')->insert(array_merge($group, [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }
}
