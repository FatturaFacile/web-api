<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountingReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        // Cost Centers
        $costCenters = [
            ['code' => 'CC-ADMIN', 'name' => 'Corporate Administration (Amministrazione generale)'],
            ['code' => 'CC-OPS', 'name' => 'Operations & Manufacturing (Produzione e logistica)'],
            ['code' => 'CC-SALES', 'name' => 'Sales & Marketing (Vendite e marketing)'],
            ['code' => 'CC-ENG', 'name' => 'Engineering & R&D (Ricerca e sviluppo)'],
        ];

        foreach ($costCenters as $cc) {
            DB::table('cost_centers')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'code' => $cc['code'],
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $tenantId,
                    'name' => $cc['name'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Profit Centers
        $profitCenters = [
            ['code' => 'PC-DOMESTIC', 'name' => 'Domestic Business Unit (Mercato interno)'],
            ['code' => 'PC-EXPORT', 'name' => 'International & Export (Mercati esteri)'],
            ['code' => 'PC-SERVICES', 'name' => 'Professional Services BU (Servizi professionali)'],
        ];

        foreach ($profitCenters as $pc) {
            DB::table('profit_centers')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'code' => $pc['code'],
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $tenantId,
                    'name' => $pc['name'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // VAT Registers (Italian Statutory)
        $registers = [
            ['code' => 'REG_ACQ_1', 'name' => 'Registro IVA Acquisti (Purchases VAT Register)', 'type' => 'purchases'],
            ['code' => 'REG_VEN_1', 'name' => 'Registro IVA Vendite (Sales VAT Register)', 'type' => 'sales'],
            ['code' => 'REG_CORR_1', 'name' => 'Registro IVA Corrispettivi (Cash Receipts Register)', 'type' => 'cash_receipts'],
        ];

        foreach ($registers as $r) {
            DB::table('vat_registers')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'code' => $r['code'],
                    'progressive_protocol_year' => 2026,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'company_id' => $tenantId,
                    'name' => $r['name'],
                    'register_type' => $r['type'],
                    'last_protocol_number' => 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
