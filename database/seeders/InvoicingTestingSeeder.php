<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class InvoicingTestingSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $tenantId = '00000000-0000-0000-0000-000000000001';
        $existingTenant = DB::table('tenants')->where('id', $tenantId)->first();
        if (! $existingTenant) {
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'name' => 'Testing Tenant',
                'slug' => 'test-tenant',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingGroup = DB::table('customer_groups')
            ->where('tenant_id', $tenantId)
            ->where('code', 'TEST_KEY_ACCOUNTS')
            ->first();

        if (! $existingGroup) {
            DB::table('customer_groups')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'code' => 'TEST_KEY_ACCOUNTS',
                'name' => 'Testing Key Accounts',
                'discount_percentage' => 5.0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingCustomer = DB::table('contacts')
            ->where('tenant_id', $tenantId)
            ->where('company_name', 'Testing Customer Srl')
            ->first();

        $customerId = $existingCustomer ? $existingCustomer->id : (string) Str::uuid7();

        if (! $existingCustomer) {
            DB::table('contacts')->insert([
                'id' => $customerId,
                'tenant_id' => $tenantId,
                'type' => 'client',
                'company_name' => 'Testing Customer Srl',
                'email' => 'testing@customer.example',
                'vat_number' => 'IT99988877766',
                'fiscal_code' => '99988877766',
                'country' => 'IT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingCredit = DB::table('customer_credit_profiles')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->first();

        if (! $existingCredit) {
            DB::table('customer_credit_profiles')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'customer_id' => $customerId,
                'credit_limit' => 100000.00,
                'used_credit' => 0.00,
                'pending_orders_amount' => 0.00,
                'total_exposure' => 0.00,
                'overdue_amount' => 0.00,
                'credit_status' => 'good',
                'risk_rating' => 'low',
                'is_credit_hold' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
