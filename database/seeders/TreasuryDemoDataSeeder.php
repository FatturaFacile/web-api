<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TreasuryDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $account = DB::table('bank_accounts')->where('tenant_id', $tenantId)->first();
        $bankAccountId = $account?->id ?? '019488a0-0001-7000-8000-000000000001';

        // 1. Loan Agreement
        $loanId = '019488b0-0001-7000-8000-000000000001';
        DB::table('loan_agreements')->updateOrInsert(
            ['id' => $loanId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'loan_reference' => 'FACILITY-CORP-2026-EUR',
                'lender_bank_account_id' => $bankAccountId,
                'loan_type' => 'TERM_LOAN',
                'principal_amount' => 1000000.00,
                'currency' => 'EUR',
                'interest_rate_type' => 'FLOATING',
                'nominal_rate' => 0.0390,
                'spread_rate' => 0.0175,
                'origination_date' => now()->subMonths(6)->toDateString(),
                'maturity_date' => now()->addMonths(30)->toDateString(),
                'repayment_structure' => 'AMORTIZING_QUARTERLY',
                'covenant_clause_json' => json_encode(['dscr_min' => 1.25, 'leverage_max' => 3.0]),
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Bank Guarantee
        $guaranteeId = '019488b0-0002-7000-8000-000000000001';
        DB::table('bank_guarantees')->updateOrInsert(
            ['id' => $guaranteeId],
            [
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'issuing_bank_account_id' => $bankAccountId,
                'guarantee_number' => 'BG-PERFORMANCE-2026-001',
                'guarantee_type' => 'PERFORMANCE_BOND',
                'beneficiary_name' => 'Autostrade per l\'Italia S.p.A.',
                'beneficiary_tax_id' => 'IT07516911000',
                'amount' => 150000.00,
                'currency' => 'EUR',
                'commission_rate_annual' => 0.0125,
                'issue_date' => now()->subMonths(2)->toDateString(),
                'expiration_date' => now()->addMonths(10)->toDateString(),
                'collateral_cash_blocked' => 15000.00,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 3. Working Capital Metrics
        $metricId = '019488b0-0003-7000-8000-000000000001';
        DB::table('working_capital_metrics')->updateOrInsert(
            [
                'id' => $metricId,
            ],
            [
                'tenant_id' => $tenantId,
                'metric_date' => now()->toDateString(),
                'company_id' => $tenantId,
                'accounts_receivable_total' => 450000.00,
                'accounts_payable_total' => 320000.00,
                'inventory_valuation_total' => 210000.00,
                'trailing_12m_revenue' => 3100000.00,
                'trailing_12m_cogs' => 1800000.00,
                'dso_days' => 52.4,
                'dpo_days' => 64.1,
                'dio_days' => 38.5,
                'cash_conversion_cycle_days' => 26.8, // 52.4 + 38.5 - 64.1 = 26.8
                'current_ratio' => 1.85,
                'quick_ratio' => 1.25,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
