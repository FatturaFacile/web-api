<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ProjectDemoDataSeeder extends Seeder
{
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
            $contactId = '0192b222-0000-7000-8000-000000000098';
            DB::table('contacts')->updateOrInsert(
                ['id' => $contactId],
                [
                    'tenant_id' => $tenantId,
                    'type' => 'customer',
                    'country' => 'IT',
                    'company_name' => 'Demo Industrial Client SpA',
                    'email' => 'industrial-demo@fattura-facile.it',
                    'vat_number' => 'IT98765432101',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $portfolioInfrastructureId = DB::table('project_portfolios')
            ->where('tenant_id', $tenantId)
            ->where('name', 'like', '%Infrastructure%')
            ->value('id');

        $portfolioErpId = DB::table('project_portfolios')
            ->where('tenant_id', $tenantId)
            ->where('name', 'like', '%Digitalization%')
            ->value('id');

        $portfolioServicesId = DB::table('project_portfolios')
            ->where('tenant_id', $tenantId)
            ->where('name', 'like', '%Services%')
            ->value('id');

        // 5 Archetypes
        $archetypes = [
            // 1. Construction / EPC Master Project
            [
                'number' => 'PRJ-CON-2027-01',
                'name' => 'Industrial Solar PV Facility & Substation Revamping',
                'type' => 'construction',
                'billing' => 'milestone_based',
                'contract_value' => 750000.00,
                'planned_cost' => 580000.00,
                'status' => 'active',
                'portfolio_id' => $portfolioInfrastructureId,
                'progress' => 45.0,
                'description' => 'Turnkey EPC design, civil groundworks, high-voltage transformers, and 2.5MW solar park grid tie-in',
                'phases' => [
                    ['code' => 'PH1-CIV', 'name' => 'Civil Works & Ground Foundations', 'sort' => 10],
                    ['code' => 'PH2-HV', 'name' => 'High-Voltage Switchgear & Transformers', 'sort' => 20],
                    ['code' => 'PH3-SOL', 'name' => 'PV Array Mounting & Inverters', 'sort' => 30],
                    ['code' => 'PH4-COMM', 'name' => 'Grid Compliance Testing & Handover', 'sort' => 40],
                ],
                'milestones' => [
                    ['title' => 'Civil Foundations Certified', 'amount' => 150000.00, 'date' => '2027-03-31'],
                    ['title' => 'HV Substation Energized', 'amount' => 250000.00, 'date' => '2027-06-30'],
                    ['title' => 'Final Utility Grid Synchronization', 'amount' => 350000.00, 'date' => '2027-09-30'],
                ],
            ],
            // 2. Enterprise ERP Implementation Project
            [
                'number' => 'PRJ-ERP-2027-02',
                'name' => 'Multi-Entity Cloud ERP & Manufacturing Rollout',
                'type' => 'software_dev',
                'billing' => 'fixed_price',
                'contract_value' => 320000.00,
                'planned_cost' => 240000.00,
                'status' => 'active',
                'portfolio_id' => $portfolioErpId,
                'progress' => 60.0,
                'description' => 'Full enterprise implementation of Modules 13, 14, 15, and 21 across 3 manufacturing sites',
                'phases' => [
                    ['code' => 'PH1-BP', 'name' => 'Business Blueprinting & Gap Analysis', 'sort' => 10],
                    ['code' => 'PH2-CFG', 'name' => 'System Customization & API Integrations', 'sort' => 20],
                    ['code' => 'PH3-UAT', 'name' => 'User Acceptance Testing & Data Migration', 'sort' => 30],
                    ['code' => 'PH4-GOLIVE', 'name' => 'Cutover, Go-Live & Hypercare Support', 'sort' => 40],
                ],
                'milestones' => [
                    ['title' => 'Blueprint Sign-Off', 'amount' => 64000.00, 'date' => '2027-02-28'],
                    ['title' => 'Integration SAT Passed', 'amount' => 128000.00, 'date' => '2027-05-15'],
                    ['title' => 'Production Go-Live', 'amount' => 128000.00, 'date' => '2027-08-01'],
                ],
            ],
            // 3. Professional Consulting Engagement
            [
                'number' => 'PRJ-CON-2027-03',
                'name' => 'Corporate Restructuring & Cross-Border Tax Optimization',
                'type' => 'consulting',
                'billing' => 'time_and_materials',
                'contract_value' => 120000.00,
                'planned_cost' => 75000.00,
                'status' => 'active',
                'portfolio_id' => $portfolioServicesId,
                'progress' => 30.0,
                'description' => 'Senior legal, financial and tax forensic analysis for corporate merger',
                'phases' => [
                    ['code' => 'PH1-DUE', 'name' => 'Financial & Legal Due Diligence', 'sort' => 10],
                    ['code' => 'PH2-TAX', 'name' => 'Transfer Pricing & Tax Structuring', 'sort' => 20],
                    ['code' => 'PH3-REP', 'name' => 'Board Presentation & Regulatory Filing', 'sort' => 30],
                ],
                'milestones' => [
                    ['title' => 'Preliminary Audit Report', 'amount' => 40000.00, 'date' => '2027-04-15'],
                    ['title' => 'Final Structure Clearance', 'amount' => 80000.00, 'date' => '2027-07-31'],
                ],
            ],
            // 4. Small Business / Fast-Track Project
            [
                'number' => 'PRJ-SMB-2027-04',
                'name' => 'Boutique Winery Smart Bottling Line Automation',
                'type' => 'customer',
                'billing' => 'fixed_price',
                'contract_value' => 65000.00,
                'planned_cost' => 45000.00,
                'status' => 'planned',
                'portfolio_id' => null,
                'progress' => 0.0,
                'description' => 'Installation of high-speed optical inspection and labeling automation PLC',
                'phases' => [
                    ['code' => 'PH1-MECH', 'name' => 'Mechanical Line Fitting', 'sort' => 10],
                    ['code' => 'PH2-PLC', 'name' => 'PLC Logic & Sensor Calibration', 'sort' => 20],
                ],
                'milestones' => [
                    ['title' => 'Line Commissioned (1,200 bottles/hr)', 'amount' => 65000.00, 'date' => '2027-04-30'],
                ],
            ],
            // 5. Maintenance & Facilities Contract
            [
                'number' => 'PRJ-MNT-2027-05',
                'name' => 'Metropolitan Logistics Park Annual HVAC & Fire Safety SLA',
                'type' => 'maintenance',
                'billing' => 'cost_plus',
                'contract_value' => 180000.00,
                'planned_cost' => 135000.00,
                'status' => 'active',
                'portfolio_id' => $portfolioInfrastructureId,
                'progress' => 75.0,
                'description' => '24/7 preventive and reactive maintenance for 45,000 sqm cold chain distribution warehouse',
                'phases' => [
                    ['code' => 'PH1-Q1', 'name' => 'Q1 Chiller Overhaul & Pressure Testing', 'sort' => 10],
                    ['code' => 'PH2-Q2', 'name' => 'Q2 Fire Sprinkler & Smoke Damper Recertification', 'sort' => 20],
                    ['code' => 'PH3-Q3', 'name' => 'Q3 Air Handling Units (AHU) Filter Sanitization', 'sort' => 30],
                    ['code' => 'PH4-Q4', 'name' => 'Q4 Winterization & Thermal Camera Inspections', 'sort' => 40],
                ],
                'milestones' => [
                    ['title' => 'Mid-Year Safety Compliance Certificate', 'amount' => 90000.00, 'date' => '2027-06-30'],
                    ['title' => 'Annual Closeout & Asset Health Log', 'amount' => 90000.00, 'date' => '2027-12-31'],
                ],
            ],
        ];

        foreach ($archetypes as $arch) {
            $existingProject = DB::table('projects')
                ->where('tenant_id', $tenantId)
                ->where('project_number', $arch['number'])
                ->first();

            $projectId = $existingProject?->id ?? (string) Str::uuid7();

            DB::table('projects')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'project_number' => $arch['number'],
                ],
                [
                    'id' => $projectId,
                    'company_id' => $companyId,
                    'contact_id' => $contactId,
                    'name' => $arch['name'],
                    'project_type' => $arch['type'],
                    'billing_method' => $arch['billing'],
                    'status' => $arch['status'],
                    'planned_start_date' => '2027-01-15',
                    'planned_end_date' => '2027-12-31',
                    'project_manager_user_id' => $userId,
                    'contract_value' => $arch['contract_value'],
                    'currency' => 'EUR',
                    'portfolio_id' => $arch['portfolio_id'],
                    'progress_percentage' => $arch['progress'],
                    'description' => $arch['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Establish Master Budget
            $budgetId = DB::table('project_budgets')->where('project_id', $projectId)->value('id') ?? (string) Str::uuid7();
            $marginAmount = round($arch['contract_value'] - $arch['planned_cost'], 2);
            $marginPct = round(($marginAmount / $arch['contract_value']) * 100, 2);

            DB::table('project_budgets')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                ],
                [
                    'id' => $budgetId,
                    'company_id' => $companyId,
                    'status' => 'approved',
                    'total_revenue_budget' => $arch['contract_value'],
                    'total_cost_budget' => $arch['planned_cost'],
                    'labour_budget' => round($arch['planned_cost'] * 0.40, 2),
                    'material_budget' => round($arch['planned_cost'] * 0.35, 2),
                    'supplier_budget' => round($arch['planned_cost'] * 0.15, 2),
                    'fleet_budget' => round($arch['planned_cost'] * 0.05, 2),
                    'expense_budget' => round($arch['planned_cost'] * 0.03, 2),
                    'contingency_budget' => round($arch['planned_cost'] * 0.02, 2),
                    'projected_margin_amount' => $marginAmount,
                    'projected_margin_percentage' => $marginPct,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Establish Financial & EVM Summary
            $actualCost = round($arch['planned_cost'] * ($arch['progress'] / 100) * 0.95, 2);
            $earnedValue = round($arch['planned_cost'] * ($arch['progress'] / 100), 2);
            $plannedValue = round($arch['planned_cost'] * ($arch['progress'] / 100) * 1.05, 2);
            $cpi = $actualCost > 0 ? round($earnedValue / $actualCost, 2) : 1.0;
            $spi = $plannedValue > 0 ? round($earnedValue / $plannedValue, 2) : 1.0;

            DB::table('project_financial_summaries')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                ],
                [
                    'id' => DB::table('project_financial_summaries')->where('project_id', $projectId)->value('id') ?? (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'total_contract_revenue' => $arch['contract_value'],
                    'total_actual_cost' => $actualCost,
                    'actual_labour_cost' => round($actualCost * 0.45, 2),
                    'actual_material_cost' => round($actualCost * 0.35, 2),
                    'actual_supplier_cost' => round($actualCost * 0.15, 2),
                    'actual_fleet_cost' => round($actualCost * 0.03, 2),
                    'actual_expense_cost' => round($actualCost * 0.02, 2),
                    'budget_at_completion_bac' => $arch['planned_cost'],
                    'planned_value_pv' => $plannedValue,
                    'earned_value_ev' => $earnedValue,
                    'cost_performance_index_cpi' => $cpi,
                    'schedule_performance_index_spi' => $spi,
                    'estimate_at_completion_eac' => $cpi > 0 ? round($arch['planned_cost'] / $cpi, 2) : $arch['planned_cost'],
                    'last_recalculated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            // Populate Phases & Milestones
            foreach ($arch['phases'] as $phase) {
                DB::table('project_phases')->updateOrInsert(
                    [
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'name' => $phase['name'],
                    ],
                    [
                        'id' => DB::table('project_phases')->where('project_id', $projectId)->where('name', $phase['name'])->value('id') ?? (string) Str::uuid7(),
                        'company_id' => $companyId,
                        'sequence_order' => $phase['sort'],
                        'status' => 'active',
                        'planned_start_date' => '2027-01-15',
                        'planned_end_date' => '2027-12-31',
                        'progress_percentage' => $arch['progress'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            foreach ($arch['milestones'] as $milestone) {
                DB::table('project_milestones')->updateOrInsert(
                    [
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'name' => $milestone['title'],
                    ],
                    [
                        'id' => DB::table('project_milestones')->where('project_id', $projectId)->where('name', $milestone['title'])->value('id') ?? (string) Str::uuid7(),
                        'company_id' => $companyId,
                        'milestone_date' => $milestone['date'],
                        'billing_amount' => $milestone['amount'],
                        'billing_percentage' => round(($milestone['amount'] / $arch['contract_value']) * 100, 2),
                        'is_completed' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
