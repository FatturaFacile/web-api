<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProjectTestingSeeder extends Seeder
{
    public const TEST_PROJECT_ID = '0192b222-0000-7000-8000-000000000001';

    public const TEST_PHASE_ID = '0192b222-0000-7000-8000-000000000002';

    public const TEST_TASK_ID = '0192b222-0000-7000-8000-000000000003';

    public const TEST_BUDGET_ID = '0192b222-0000-7000-8000-000000000004';

    public const TEST_FINANCIAL_SUMMARY_ID = '0192b222-0000-7000-8000-000000000005';

    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';
        $companyId = Schema::hasTable('companies') ? DB::table('companies')->value('id') : null;
        $companyId = $companyId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c';

        $userId = Schema::hasTable('users') ? DB::table('users')->value('id') : null;
        $userId = $userId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6d';

        $contactId = DB::table('contacts')->where('tenant_id', $tenantId)->value('id');
        if (! $contactId) {
            $contactId = '0192b222-0000-7000-8000-000000000099';
            DB::table('contacts')->updateOrInsert(
                ['id' => $contactId],
                [
                    'tenant_id' => $tenantId,
                    'type' => 'customer',
                    'country' => 'IT',
                    'company_name' => 'Automated Test Client S.r.l.',
                    'email' => 'test-client@enterprise-demo.it',
                    'vat_number' => 'IT12345678901',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // 1. Deterministic Master Test Project
        DB::table('projects')->updateOrInsert(
            ['id' => self::TEST_PROJECT_ID],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'project_number' => 'PRJ-TEST-AUTOMATION-01',
                'name' => 'Automated Integration Test Project',
                'project_type' => 'construction',
                'billing_method' => 'fixed_price',
                'status' => 'active',
                'planned_start_date' => '2027-01-01',
                'planned_end_date' => '2027-12-31',
                'project_manager_user_id' => $userId,
                'contract_value' => 100000.00,
                'currency' => 'EUR',
                'progress_percentage' => 20.0,
                'description' => 'Target project for deterministic unit, feature, and concurrency tests',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 2. Deterministic Test Phase
        DB::table('project_phases')->updateOrInsert(
            ['id' => self::TEST_PHASE_ID],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'project_id' => self::TEST_PROJECT_ID,
                'sequence_order' => 10,
                'name' => 'Initial Phase Testing',
                'status' => 'active',
                'planned_start_date' => '2027-01-01',
                'planned_end_date' => '2027-06-30',
                'progress_percentage' => 50.0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 3. Deterministic Test Task
        DB::table('project_tasks')->updateOrInsert(
            ['id' => self::TEST_TASK_ID],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'project_id' => self::TEST_PROJECT_ID,
                'project_phase_id' => self::TEST_PHASE_ID,
                'task_number' => 'TSK-TEST-001',
                'title' => 'Foundation Pouring & Sensor Wiring',
                'status' => 'in_progress',
                'priority' => 'high',
                'planned_start_date' => '2027-01-01',
                'planned_end_date' => '2027-03-31',
                'progress_percentage' => 40.0,
                'estimated_hours' => 120.0,
                'actual_hours' => 45.0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 4. Deterministic Test Budget
        DB::table('project_budgets')->updateOrInsert(
            ['id' => self::TEST_BUDGET_ID],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'project_id' => self::TEST_PROJECT_ID,
                'status' => 'approved',
                'total_revenue_budget' => 100000.00,
                'total_cost_budget' => 80000.00,
                'labour_budget' => 35000.00,
                'material_budget' => 25000.00,
                'supplier_budget' => 12000.00,
                'fleet_budget' => 4000.00,
                'expense_budget' => 2000.00,
                'contingency_budget' => 2000.00,
                'projected_margin_amount' => 20000.00,
                'projected_margin_percentage' => 20.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 5. Deterministic Test Financial Summary
        DB::table('project_financial_summaries')->updateOrInsert(
            ['id' => self::TEST_FINANCIAL_SUMMARY_ID],
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'project_id' => self::TEST_PROJECT_ID,
                'total_contract_revenue' => 100000.00,
                'total_actual_cost' => 16000.00,
                'budget_at_completion_bac' => 80000.00,
                'planned_value_pv' => 20000.00,
                'earned_value_ev' => 18000.00,
                'cost_performance_index_cpi' => 1.13,
                'schedule_performance_index_spi' => 0.90,
                'estimate_at_completion_eac' => 70796.46,
                'last_recalculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
