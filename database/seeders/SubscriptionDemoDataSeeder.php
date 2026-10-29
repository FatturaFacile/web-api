<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SubscriptionDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            $customerId = (string) Str::uuid7();

            // Scenario 1: SaaS Company - Cloud Enterprise Annual
            $planSaaS = DB::table('sub_plans')
                ->where('tenant_id', $tenant->id)
                ->where('plan_code', 'SAAS-ENT-ANNUAL')
                ->first();

            if ($planSaaS) {
                $sub1 = DB::table('sub_subscriptions')
                    ->where('tenant_id', $tenant->id)
                    ->where('subscription_number', 'SUB-DEMO-SAAS-001')
                    ->first();

                $sub1Id = $sub1?->id ?? (string) Str::uuid7();

                DB::table('sub_subscriptions')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'subscription_number' => 'SUB-DEMO-SAAS-001'],
                    [
                        'id' => $sub1Id,
                        'company_id' => $tenant->id,
                        'customer_id' => $customerId,
                        'plan_id' => $planSaaS->id,
                        'status' => 'active',
                        'start_date' => now()->subMonths(6)->toDateString(),
                        'current_period_start' => now()->subMonths(6)->toDateString(),
                        'current_period_end' => now()->addMonths(6)->toDateString(),
                        'renewal_type' => 'evergreen',
                        'contract_term_months' => 12,
                        'auto_renew' => true,
                        'cancellation_notice_days' => 30,
                        'currency' => 'EUR',
                        'mrr' => 200.00,
                        'arr' => 2400.00,
                        'is_locked' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Scenario 2: Managed Service Provider (MSP IT Retainer)
            $planPro = DB::table('sub_plans')
                ->where('tenant_id', $tenant->id)
                ->where('plan_code', 'SAAS-PRO-M')
                ->first();

            if ($planPro) {
                $sub2 = DB::table('sub_subscriptions')
                    ->where('tenant_id', $tenant->id)
                    ->where('subscription_number', 'SUB-DEMO-MSP-001')
                    ->first();

                $sub2Id = $sub2?->id ?? (string) Str::uuid7();

                DB::table('sub_subscriptions')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'subscription_number' => 'SUB-DEMO-MSP-001'],
                    [
                        'id' => $sub2Id,
                        'company_id' => $tenant->id,
                        'customer_id' => $customerId,
                        'plan_id' => $planPro->id,
                        'status' => 'active',
                        'start_date' => now()->subMonths(3)->toDateString(),
                        'current_period_start' => now()->startOfMonth()->toDateString(),
                        'current_period_end' => now()->endOfMonth()->toDateString(),
                        'renewal_type' => 'evergreen',
                        'contract_term_months' => 12,
                        'auto_renew' => true,
                        'cancellation_notice_days' => 30,
                        'currency' => 'EUR',
                        'mrr' => 850.00,
                        'arr' => 10200.00,
                        'is_locked' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Scenario 3: Consulting Subscription (Architecture Advisory Retainer)
            $sub3 = DB::table('sub_subscriptions')
                ->where('tenant_id', $tenant->id)
                ->where('subscription_number', 'SUB-DEMO-CONS-001')
                ->first();

            $sub3Id = $sub3?->id ?? (string) Str::uuid7();

            if ($planPro) {
                DB::table('sub_subscriptions')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'subscription_number' => 'SUB-DEMO-CONS-001'],
                    [
                        'id' => $sub3Id,
                        'company_id' => $tenant->id,
                        'customer_id' => $customerId,
                        'plan_id' => $planPro->id,
                        'status' => 'active',
                        'start_date' => now()->subMonths(1)->toDateString(),
                        'current_period_start' => now()->startOfMonth()->toDateString(),
                        'current_period_end' => now()->endOfMonth()->toDateString(),
                        'renewal_type' => 'fixed_term',
                        'contract_term_months' => 6,
                        'auto_renew' => false,
                        'cancellation_notice_days' => 15,
                        'currency' => 'EUR',
                        'mrr' => 1200.00,
                        'arr' => 14400.00,
                        'is_locked' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Scenario 4: Enterprise Support Contract (Hybrid Metred Usage)
            $planUsage = DB::table('sub_plans')
                ->where('tenant_id', $tenant->id)
                ->where('plan_code', 'SAAS-METERED-USAGE')
                ->first();

            if ($planUsage) {
                $sub4 = DB::table('sub_subscriptions')
                    ->where('tenant_id', $tenant->id)
                    ->where('subscription_number', 'SUB-DEMO-SUPP-001')
                    ->first();

                $sub4Id = $sub4?->id ?? (string) Str::uuid7();

                DB::table('sub_subscriptions')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'subscription_number' => 'SUB-DEMO-SUPP-001'],
                    [
                        'id' => $sub4Id,
                        'company_id' => $tenant->id,
                        'customer_id' => $customerId,
                        'plan_id' => $planUsage->id,
                        'status' => 'active',
                        'start_date' => now()->subMonths(4)->toDateString(),
                        'current_period_start' => now()->startOfMonth()->toDateString(),
                        'current_period_end' => now()->endOfMonth()->toDateString(),
                        'renewal_type' => 'evergreen',
                        'contract_term_months' => 12,
                        'auto_renew' => true,
                        'cancellation_notice_days' => 30,
                        'currency' => 'EUR',
                        'mrr' => 450.00,
                        'arr' => 5400.00,
                        'is_locked' => false,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
