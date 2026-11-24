<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BankAccountSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $accounts = [
            [
                'bank_name' => 'Intesa Sanpaolo S.p.A.',
                'branch_name' => 'Milano Sede Centrale',
                'account_number' => 'ACC-EUR-MAIN-001',
                'account_type' => 'OPERATING',
                'iban' => 'IT60X0542811101000000123456',
                'bic_swift' => 'BCITITMM',
                'currency' => 'EUR',
                'opening_balance' => 500000.00,
                'current_balance' => 750000.00,
                'available_balance' => 950000.00,
                'overdraft_limit' => 200000.00,
                'credit_line_limit' => 500000.00,
                'is_default' => true,
                'is_pool_header' => true,
            ],
            [
                'bank_name' => 'UniCredit S.p.A.',
                'branch_name' => 'Milano Corporate Tower',
                'account_number' => 'ACC-EUR-PAYROLL-002',
                'account_type' => 'PAYROLL',
                'iban' => 'IT70Y020080100000000987654',
                'bic_swift' => 'UNCRITM1',
                'currency' => 'EUR',
                'opening_balance' => 150000.00,
                'current_balance' => 220000.00,
                'available_balance' => 270000.00,
                'overdraft_limit' => 50000.00,
                'credit_line_limit' => 100000.00,
                'is_default' => false,
                'is_pool_header' => false,
            ],
            [
                'bank_name' => 'BNP Paribas BNL',
                'branch_name' => 'Roma Direzione Generale',
                'account_number' => 'ACC-USD-TRADE-003',
                'account_type' => 'OPERATING',
                'iban' => 'IT80Z0100503382000001122334',
                'bic_swift' => 'BNLIITRR',
                'currency' => 'USD',
                'opening_balance' => 250000.00,
                'current_balance' => 380000.00,
                'available_balance' => 380000.00,
                'overdraft_limit' => 0.00,
                'credit_line_limit' => 250000.00,
                'is_default' => false,
                'is_pool_header' => false,
            ],
            [
                'bank_name' => 'UBS Switzerland AG',
                'branch_name' => 'Zurich Paradeplatz',
                'account_number' => 'ACC-CHF-TREASURY-004',
                'account_type' => 'TREASURY',
                'iban' => 'CH9300230000109988776',
                'bic_swift' => 'UBSWCHZH80A',
                'currency' => 'CHF',
                'opening_balance' => 100000.00,
                'current_balance' => 180000.00,
                'available_balance' => 180000.00,
                'overdraft_limit' => 0.00,
                'credit_line_limit' => 150000.00,
                'is_default' => false,
                'is_pool_header' => false,
            ],
        ];

        foreach ($accounts as $acc) {
            $existing = DB::table('bank_accounts')
                ->where('tenant_id', $tenantId)
                ->where('iban', $acc['iban'])
                ->first();

            if ($existing) {
                DB::table('bank_accounts')->where('id', $existing->id)->update([
                    'bank_name' => $acc['bank_name'],
                    'branch_name' => $acc['branch_name'],
                    'account_number' => $acc['account_number'],
                    'account_type' => $acc['account_type'],
                    'bic_swift' => $acc['bic_swift'],
                    'currency' => $acc['currency'],
                    'opening_balance' => $acc['opening_balance'],
                    'current_balance' => $acc['current_balance'],
                    'available_balance' => $acc['available_balance'],
                    'overdraft_limit' => $acc['overdraft_limit'],
                    'credit_line_limit' => $acc['credit_line_limit'],
                    'is_default' => $acc['is_default'],
                    'is_pool_header' => $acc['is_pool_header'],
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('bank_accounts')->insert(array_merge($acc, [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }
}
