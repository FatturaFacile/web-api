<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class SalesRepresentativeSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        if (! $tenantId) {
            return;
        }

        $reps = [
            [
                'rep_code' => 'REP-NORD-01',
                'name' => 'Marco Rossi (Nord Italia / North Italy / Norditalien)',
                'email' => 'm.rossi@enterprise-erp.example',
                'phone' => '+39 02 87654321',
                'territory' => 'Lombardia, Piemonte, Veneto',
                'default_commission_rate' => 3.50,
            ],
            [
                'rep_code' => 'REP-CH-01',
                'name' => 'Stefan Meier (Svizzera / Switzerland / Schweiz / Suisse)',
                'email' => 's.meier@enterprise-erp.example',
                'phone' => '+41 44 123 45 67',
                'territory' => 'Ticino, Zurich, Geneva',
                'default_commission_rate' => 4.00,
            ],
            [
                'rep_code' => 'REP-DACH-01',
                'name' => 'Hans Schmidt (DACH Region)',
                'email' => 'h.schmidt@enterprise-erp.example',
                'phone' => '+49 89 98765432',
                'territory' => 'Germany, Austria',
                'default_commission_rate' => 3.75,
            ],
        ];

        foreach ($reps as $rep) {
            $existing = DB::table('sales_representatives')
                ->where('tenant_id', $tenantId)
                ->where('rep_code', $rep['rep_code'])
                ->first();

            if (! $existing) {
                DB::table('sales_representatives')->insert(array_merge($rep, [
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
