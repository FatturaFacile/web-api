<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardLayoutSeeder extends Seeder
{
    /**
     * Seed executive and operational dashboards for all tenants.
     */
    public function run(): void
    {
        $tenants = Tenant::all();

        $adminUser = User::where('email', 'admin@demo-group.com')->first() ?? User::first();
        $cfoUser = User::where('email', 'cfo@demo-group.com')->first() ?? $adminUser;
        $salesUser = User::where('email', 'sales@demo-group.com')->first() ?? $adminUser;
        $mfgUser = User::where('email', 'mfg@demo-group.com')->first() ?? $adminUser;
        $serviceUser = User::where('email', 'service@demo-group.com')->first() ?? $adminUser;

        $dashboards = [
            [
                'name' => 'CEO Executive Dashboard',
                'user_id' => $adminUser?->id,
                'is_default' => true,
                'widgets' => [
                    ['id' => 'kpi_arr', 'type' => 'metric_card', 'title' => 'Annual Recurring Revenue (ARR)', 'metric' => 'EUR 14,850,000', 'trend' => '+18.4% YoY', 'grid' => ['x' => 0, 'y' => 0, 'w' => 3, 'h' => 2]],
                    ['id' => 'kpi_ebitda', 'type' => 'metric_card', 'title' => 'EBITDA Margin', 'metric' => '24.2%', 'trend' => '+2.1% QoQ', 'grid' => ['x' => 3, 'y' => 0, 'w' => 3, 'h' => 2]],
                    ['id' => 'kpi_oee', 'type' => 'metric_card', 'title' => 'Plant OEE Average', 'metric' => '86.4%', 'trend' => '+3.5% vs target', 'grid' => ['x' => 6, 'y' => 0, 'w' => 3, 'h' => 2]],
                    ['id' => 'kpi_csat', 'type' => 'metric_card', 'title' => 'Global CSAT Score', 'metric' => '4.85 / 5.0', 'trend' => '98% Positive', 'grid' => ['x' => 9, 'y' => 0, 'w' => 3, 'h' => 2]],
                    ['id' => 'chart_rev_ebitda', 'type' => 'combo_chart', 'title' => 'Revenue vs Operating Profit by Division', 'source' => 'gl_reports', 'grid' => ['x' => 0, 'y' => 2, 'w' => 8, 'h' => 4]],
                    ['id' => 'feed_audit_alerts', 'type' => 'alert_feed', 'title' => 'Executive Audit & Governance Alerts', 'source' => 'audit_ledger', 'grid' => ['x' => 8, 'y' => 2, 'w' => 4, 'h' => 4]],
                ],
            ],
            [
                'name' => 'COO Operations Dashboard',
                'user_id' => $adminUser?->id,
                'is_default' => false,
                'widgets' => [
                    ['id' => 'kpi_fulfillment', 'type' => 'metric_card', 'title' => 'Order On-Time In-Full (OTIF)', 'metric' => '96.8%', 'trend' => '+1.2%', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_downtime', 'type' => 'metric_card', 'title' => 'Unplanned Machine Downtime', 'metric' => '1.8%', 'trend' => 'Down 0.6%', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_inventory_turns', 'type' => 'metric_card', 'title' => 'Inventory Turnover Ratio', 'metric' => '7.4x', 'trend' => '+0.8x', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'chart_capacity_util', 'type' => 'gauge_grid', 'title' => 'Work Center Capacity Load vs Limits', 'source' => 'work_centers', 'grid' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 4]],
                ],
            ],
            [
                'name' => 'CFO Financial Dashboard',
                'user_id' => $cfoUser?->id,
                'is_default' => true,
                'widgets' => [
                    ['id' => 'kpi_cash', 'type' => 'metric_card', 'title' => 'Total Liquidity & Cash Reserves', 'metric' => 'EUR 6,240,000', 'trend' => 'Safe runway 14 mos', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_dso', 'type' => 'metric_card', 'title' => 'Days Sales Outstanding (DSO)', 'metric' => '41.2 Days', 'trend' => '-3.8 days', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_wip', 'type' => 'metric_card', 'title' => 'Active WIP Inventory Valuation', 'metric' => 'EUR 1,890,450', 'trend' => 'Standard Costing', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'chart_cashflow_forecast', 'type' => 'line_chart', 'title' => '13-Week Rolling Cash Flow Projection', 'source' => 'treasury_forecast', 'grid' => ['x' => 0, 'y' => 2, 'w' => 6, 'h' => 4]],
                    ['id' => 'chart_variance_analysis', 'type' => 'bar_chart', 'title' => 'Cost Variance Breakdown (Material / Labor / Machine)', 'source' => 'cost_variance', 'grid' => ['x' => 6, 'y' => 2, 'w' => 6, 'h' => 4]],
                ],
            ],
            [
                'name' => 'Treasury & Liquidity Dashboard',
                'user_id' => $cfoUser?->id,
                'is_default' => false,
                'widgets' => [
                    ['id' => 'kpi_net_cash', 'type' => 'metric_card', 'title' => 'Net Operating Cash Inflow', 'metric' => '+EUR 780,200', 'trend' => 'Monthly surplus', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_credit_drawn', 'type' => 'metric_card', 'title' => 'Credit Lines Utilization', 'metric' => '12.5%', 'trend' => 'EUR 3.5M headroom', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_hedging', 'type' => 'metric_card', 'title' => 'Currency FX Exposure Hedged', 'metric' => '94.0%', 'trend' => 'USD & CHF exposure', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'chart_bank_balances', 'type' => 'stacked_bar', 'title' => 'Cash Allocation Across Banking Partners', 'source' => 'treasury_bank_accounts', 'grid' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 4]],
                ],
            ],
            [
                'name' => 'Sales & Revenue Dashboard',
                'user_id' => $salesUser?->id,
                'is_default' => true,
                'widgets' => [
                    ['id' => 'kpi_pipeline', 'type' => 'metric_card', 'title' => 'Qualified Sales Pipeline', 'metric' => 'EUR 8,420,000', 'trend' => 'Weighted EUR 4.9M', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_win_rate', 'type' => 'metric_card', 'title' => 'Opportunity Win Rate', 'metric' => '32.6%', 'trend' => '+4.1%', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_mrr_growth', 'type' => 'metric_card', 'title' => 'Net New MRR', 'metric' => '+EUR 45,200', 'trend' => 'Gross MRR EUR 840K', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'chart_pipeline_funnel', 'type' => 'funnel_chart', 'title' => 'Enterprise Deals by Pipeline Stage', 'source' => 'crm_pipeline', 'grid' => ['x' => 0, 'y' => 2, 'w' => 7, 'h' => 4]],
                    ['id' => 'table_top_quotes', 'type' => 'data_table', 'title' => 'High Value Active Proposals', 'source' => 'sales_quotes', 'grid' => ['x' => 7, 'y' => 2, 'w' => 5, 'h' => 4]],
                ],
            ],
            [
                'name' => 'Manufacturing & Quality Dashboard',
                'user_id' => $mfgUser?->id,
                'is_default' => true,
                'widgets' => [
                    ['id' => 'kpi_prod_output', 'type' => 'metric_card', 'title' => 'Units Produced This Month', 'metric' => '4,280 Units', 'trend' => '104% of plan', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_scrap_rate', 'type' => 'metric_card', 'title' => 'Production Scrap Rate', 'metric' => '0.82%', 'trend' => 'Below 1.0% limit', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_ncr_open', 'type' => 'metric_card', 'title' => 'Open Non-Conformance Reports', 'metric' => '2 Active NCRs', 'trend' => 'All in quarantine', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'chart_oee_breakdown', 'type' => 'waterfall_chart', 'title' => 'OEE Loss Breakdown (Availability / Performance / Quality)', 'source' => 'mfg_metrics', 'grid' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 4]],
                ],
            ],
            [
                'name' => 'Service & Field Maintenance Dashboard',
                'user_id' => $serviceUser?->id,
                'is_default' => true,
                'widgets' => [
                    ['id' => 'kpi_open_tickets', 'type' => 'metric_card', 'title' => 'Active Support Tickets', 'metric' => '24 Tickets', 'trend' => '95.8% SLA on track', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_mttr', 'type' => 'metric_card', 'title' => 'Mean Time to Resolution (MTTR)', 'metric' => '3.4 Hours', 'trend' => '-25 mins vs target', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_ftr', 'type' => 'metric_card', 'title' => 'First-Time Fix Rate (FTFR)', 'metric' => '89.2%', 'trend' => '+3.4%', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'map_technician_dispatch', 'type' => 'geo_map', 'title' => 'Live Field Technician Deployments', 'source' => 'esm_visits', 'grid' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 4]],
                ],
            ],
            [
                'name' => 'HR & Workforce Dashboard',
                'user_id' => $serviceUser?->id,
                'is_default' => false,
                'widgets' => [
                    ['id' => 'kpi_headcount', 'type' => 'metric_card', 'title' => 'Active Headcount', 'metric' => '342 Employees', 'trend' => '+12 this quarter', 'grid' => ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_absenteeism', 'type' => 'metric_card', 'title' => 'Absenteeism Rate', 'metric' => '2.1%', 'trend' => 'Below industry norm', 'grid' => ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'kpi_utilization', 'type' => 'metric_card', 'title' => 'Billable Project Utilization', 'metric' => '84.5%', 'trend' => 'Target 80%', 'grid' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 2]],
                    ['id' => 'chart_headcount_dept', 'type' => 'donut_chart', 'title' => 'Workforce Distribution by Department', 'source' => 'hr_departments', 'grid' => ['x' => 0, 'y' => 2, 'w' => 6, 'h' => 4]],
                    ['id' => 'calendar_leave_schedule', 'type' => 'gantt_chart', 'title' => 'Approved Vacations & Shift Rotations', 'source' => 'hr_leaves', 'grid' => ['x' => 6, 'y' => 2, 'w' => 6, 'h' => 4]],
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            foreach ($dashboards as $dash) {
                if (! $dash['user_id']) {
                    continue;
                }

                DB::table('dashboard_layouts')->updateOrInsert(
                    [
                        'tenant_id' => $tenant->id,
                        'user_id' => $dash['user_id'],
                        'is_default' => $dash['is_default'],
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'name' => $dash['name'],
                        'widgets_layout' => json_encode($dash['widgets']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
