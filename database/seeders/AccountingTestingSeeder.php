<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AccountingTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        // Deterministic Test User
        $testUserId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a99';
        DB::table('users')->updateOrInsert(
            ['id' => $testUserId],
            [
                'name' => 'General Ledger Chief Accountant',
                'email' => 'chief.accountant@erp-enterprise.test',
                'password' => bcrypt('password123'),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        if (Schema::hasTable('tenant_user')) {
            DB::table('tenant_user')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $testUserId,
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Deterministic Test Customer & Supplier Contact
        $testCustomerId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a88';
        if (Schema::hasTable('contacts')) {
            DB::table('contacts')->updateOrInsert(
                ['id' => $testCustomerId],
                [
                    'tenant_id' => $tenantId,
                    'type' => 'client',
                    'company_name' => 'Test Enterprise Customer SpA',
                    'vat_number' => 'IT12345678901',
                    'email' => 'finance@customer-test.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $testSupplierId = '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a77';
            DB::table('contacts')->updateOrInsert(
                ['id' => $testSupplierId],
                [
                    'tenant_id' => $tenantId,
                    'type' => 'supplier',
                    'company_name' => 'Test Industrial Supplier Srl',
                    'vat_number' => 'IT98765432109',
                    'email' => 'ar@supplier-test.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
