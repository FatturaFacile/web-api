<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class TreasuryTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        // 1. Dedicated Test Bank Account
        $testAccountId = '019488a0-0001-7000-8000-000000000001';
        DB::table('bank_accounts')->updateOrInsert(
            [
                'id' => $testAccountId,
            ],
            [
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'bank_name' => 'Automated Test Bank Intesa',
                'branch_name' => 'Test Branch',
                'account_number' => 'ACC-TEST-EUR-001',
                'account_type' => 'OPERATING',
                'iban' => 'IT99T030690160000000999999',
                'bic_swift' => 'BCITITMM',
                'currency' => 'EUR',
                'opening_balance' => 100000.00,
                'current_balance' => 100000.00,
                'available_balance' => 150000.00,
                'overdraft_limit' => 50000.00,
                'credit_line_limit' => 100000.00,
                'is_default' => false,
                'is_pool_header' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Test Receivable Collections
        $receivables = [
            [
                'id' => '019488a0-0002-7000-8000-000000000001',
                'customer_id' => (string) Str::uuid7(),
                'invoice_id' => (string) Str::uuid7(),
                'expected_amount' => 5000.00,
                'collected_amount' => 0.00,
                'due_date' => now()->addDays(15)->toDateString(),
                'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
                'status' => 'PENDING',
                'aging_bucket' => 'CURRENT',
                'dunning_level' => 0,
            ],
            [
                'id' => '019488a0-0002-7000-8000-000000000002',
                'customer_id' => (string) Str::uuid7(),
                'invoice_id' => (string) Str::uuid7(),
                'expected_amount' => 12500.00,
                'collected_amount' => 0.00,
                'due_date' => now()->subDays(20)->toDateString(),
                'payment_method' => 'SEPA_DIRECT_DEBIT_CORE',
                'status' => 'OVERDUE',
                'aging_bucket' => '1_30',
                'dunning_level' => 1,
            ],
        ];

        foreach ($receivables as $rc) {
            DB::table('receivable_collections')->updateOrInsert(
                ['id' => $rc['id']],
                array_merge($rc, [
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
