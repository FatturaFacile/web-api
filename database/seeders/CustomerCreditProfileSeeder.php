<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CustomerCreditProfileSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('contacts') || ! Schema::hasTable('customer_credit_profiles')) {
            return;
        }

        $customers = DB::table('contacts')->get();

        foreach ($customers as $customer) {
            $existing = DB::table('customer_credit_profiles')
                ->where('tenant_id', $customer->tenant_id)
                ->where('customer_id', $customer->id)
                ->first();

            if (! $existing) {
                DB::table('customer_credit_profiles')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $customer->tenant_id,
                    'customer_id' => $customer->id,
                    'credit_limit' => 75000.00,
                    'used_credit' => 0.00,
                    'pending_orders_amount' => 0.00,
                    'total_exposure' => 0.00,
                    'overdue_amount' => 0.00,
                    'overdue_invoices_count' => 0,
                    'max_overdue_days' => 0,
                    'credit_status' => 'good',
                    'risk_rating' => 'low',
                    'is_credit_hold' => false,
                    'last_evaluated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
