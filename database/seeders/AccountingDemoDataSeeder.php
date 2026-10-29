<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountingDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $period = DB::table('fiscal_periods')->where('tenant_id', $tenantId)->where('period_number', 1)->first();
        if (! $period) {
            return;
        }

        $accCash = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '1010')->first();
        $accAr = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '1100')->first();
        $accAp = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '2000')->first();
        $accRev = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '4000')->first();
        $accCogs = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '5000')->first();
        $accInv = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '1200')->first();
        $accVatSales = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '2200')->first();
        $accWip = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('account_code', '1250')->first();

        if (! ($accCash && $accAr && $accAp && $accRev && $accCogs && $accInv)) {
            return;
        }

        // Demo 1: SME Sales Invoice (Dr: AR 12,200 | Cr: Revenue 10,000, Cr: Sales VAT 2,200)
        $smeEntryId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5d01';
        DB::table('journal_entries')->updateOrInsert(
            ['id' => $smeEntryId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'DEMO-SME-INV-001',
                'posting_date' => '2026-01-15',
                'document_date' => '2026-01-15',
                'ledger_type' => 'statutory',
                'journal_type' => 'standard',
                'description' => 'Demo SME Sales Invoice #1024 with 22% VAT',
                'source_document_type' => 'invoice',
                'status' => 'posted',
                'total_amount' => 12200.00,
                'posted_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('journal_lines')->where('journal_entry_id', $smeEntryId)->delete();
        DB::table('journal_lines')->insert([
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $smeEntryId,
                'posting_date' => '2026-01-15',
                'line_number' => 1,
                'account_id' => $accAr->id,
                'partner_type' => 'customer',
                'description' => 'Accounts Receivable from Client',
                'debit_amount' => 12200.00,
                'credit_amount' => 0.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 12200.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $smeEntryId,
                'posting_date' => '2026-01-15',
                'line_number' => 2,
                'account_id' => $accRev->id,
                'partner_type' => null,
                'description' => 'Sales Revenue',
                'debit_amount' => 0.00,
                'credit_amount' => 10000.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 10000.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $smeEntryId,
                'posting_date' => '2026-01-15',
                'line_number' => 3,
                'account_id' => $accVatSales ? $accVatSales->id : $accRev->id,
                'partner_type' => null,
                'description' => 'Sales VAT 22%',
                'debit_amount' => 0.00,
                'credit_amount' => 2200.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 2200.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Demo 2: Manufacturing Material Consumption (Dr: COGS 4,500 | Cr: Inventory 4,500)
        $mfgEntryId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5d02';
        DB::table('journal_entries')->updateOrInsert(
            ['id' => $mfgEntryId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'DEMO-MFG-COGS-001',
                'posting_date' => '2026-01-20',
                'document_date' => '2026-01-20',
                'ledger_type' => 'statutory',
                'journal_type' => 'standard',
                'description' => 'Raw Material Issuance & COGS Recognition',
                'source_document_type' => 'inventory_movement',
                'status' => 'posted',
                'total_amount' => 4500.00,
                'posted_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('journal_lines')->where('journal_entry_id', $mfgEntryId)->delete();
        DB::table('journal_lines')->insert([
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $mfgEntryId,
                'posting_date' => '2026-01-20',
                'line_number' => 1,
                'account_id' => $accCogs->id,
                'partner_type' => null,
                'description' => 'Cost of Goods Sold (Raw Material)',
                'debit_amount' => 4500.00,
                'credit_amount' => 0.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 4500.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $mfgEntryId,
                'posting_date' => '2026-01-20',
                'line_number' => 2,
                'account_id' => $accInv->id,
                'partner_type' => null,
                'description' => 'Inventory Asset Relief',
                'debit_amount' => 0.00,
                'credit_amount' => 4500.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 4500.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Demo 3: Project WIP Capitalization (Dr: WIP 3,000 | Cr: AP Subcontractor 3,000)
        $prjEntryId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5d03';
        DB::table('journal_entries')->updateOrInsert(
            ['id' => $prjEntryId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'DEMO-PRJ-WIP-001',
                'posting_date' => '2026-01-25',
                'document_date' => '2026-01-25',
                'ledger_type' => 'statutory',
                'journal_type' => 'standard',
                'description' => 'Subcontractor Job Cost WIP Capitalization',
                'source_document_type' => 'project_cost_entry',
                'status' => 'posted',
                'total_amount' => 3000.00,
                'posted_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('journal_lines')->where('journal_entry_id', $prjEntryId)->delete();
        DB::table('journal_lines')->insert([
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $prjEntryId,
                'posting_date' => '2026-01-25',
                'line_number' => 1,
                'account_id' => $accWip ? $accWip->id : $accInv->id,
                'partner_type' => null,
                'description' => 'WIP Subcontractor Cost',
                'debit_amount' => 3000.00,
                'credit_amount' => 0.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 3000.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'journal_entry_id' => $prjEntryId,
                'posting_date' => '2026-01-25',
                'line_number' => 2,
                'account_id' => $accAp->id,
                'partner_type' => 'supplier',
                'description' => 'Subcontractor Payable',
                'debit_amount' => 0.00,
                'credit_amount' => 3000.00,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'amount_in_functional_currency' => 3000.00,
                'is_cleared' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
