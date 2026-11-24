<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionTestingSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $plan = DB::table('sub_plans')->where('tenant_id', $tenant->id)->first();
            if ($plan === null) {
                continue;
            }

            $customerId = (string) Str::uuid7();

            $existingSub = DB::table('sub_subscriptions')
                ->where('tenant_id', $tenant->id)
                ->where('subscription_number', 'SUB-TEST-SEED-001')
                ->first();

            $subId = $existingSub?->id ?? (string) Str::uuid7();

            DB::table('sub_subscriptions')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'subscription_number' => 'SUB-TEST-SEED-001'],
                [
                    'id' => $subId,
                    'company_id' => $tenant->id,
                    'customer_id' => $customerId,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'start_date' => now()->subMonths(2)->toDateString(),
                    'current_period_start' => now()->startOfMonth()->toDateString(),
                    'current_period_end' => now()->endOfMonth()->toDateString(),
                    'renewal_type' => 'evergreen',
                    'contract_term_months' => 12,
                    'auto_renew' => true,
                    'cancellation_notice_days' => 30,
                    'currency' => 'EUR',
                    'mrr' => (float) $plan->base_price,
                    'arr' => (float) $plan->base_price * 12.0,
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // Seed an active line item
            DB::table('sub_subscription_items')->updateOrInsert(
                ['subscription_id' => $subId, 'item_type' => 'plan'],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'description' => $plan->name,
                    'quantity' => 1.0,
                    'unit_price' => $plan->base_price,
                    'discount_percentage' => 0.00,
                    'subtotal_recurring' => $plan->base_price,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
