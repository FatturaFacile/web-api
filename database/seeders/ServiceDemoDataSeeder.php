<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceDemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            return;
        }

        foreach ($tenants as $tenant) {
            // 1. Scenario IT Managed Services: SLA Contract & High-Priority Ticket
            $existingContract = DB::table('esm_service_contracts')
                ->where('tenant_id', $tenant->id)
                ->where('contract_number', 'SC-DEMO-ITMS-001')
                ->first();

            $contractId = $existingContract?->id ?? (string) Str::uuid7();
            $customerId = $existingContract?->customer_id ?? (string) Str::uuid7();

            DB::table('esm_service_contracts')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'contract_number' => 'SC-DEMO-ITMS-001'],
                [
                    'id' => $contractId,
                    'company_id' => $tenant->id,
                    'title' => 'Managed Cloud & Cybersecurity SLA',
                    'customer_id' => $customerId,
                    'status' => 'active',
                    'start_date' => now()->subMonths(6)->toDateString(),
                    'end_date' => now()->addMonths(6)->toDateString(),
                    'annual_value' => 36000.00,
                    'currency' => 'EUR',
                    'auto_renew' => true,
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $existingTicket = DB::table('esm_tickets')
                ->where('tenant_id', $tenant->id)
                ->where('ticket_number', 'TCK-DEMO-ITMS-001')
                ->first();

            $ticketId = $existingTicket?->id ?? (string) Str::uuid7();

            DB::table('esm_tickets')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'ticket_number' => 'TCK-DEMO-ITMS-001'],
                [
                    'id' => $ticketId,
                    'company_id' => $tenant->id,
                    'customer_id' => $customerId,
                    'service_contract_id' => $contractId,
                    'subject' => 'Core VPN gateway latency spike',
                    'description' => 'Packet drop observed across Milan remote workforce.',
                    'priority' => 'high',
                    'status' => 'in_progress',
                    'source' => 'portal',
                    'response_due_at' => now()->addHours(2),
                    'resolution_due_at' => now()->addHours(6),
                    'is_escalated' => false,
                    'escalation_level' => 0,
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // 2. Scenario Field Service: Work Order with On-Site Dispatch & Appointment
            $existingWo = DB::table('esm_work_orders')
                ->where('tenant_id', $tenant->id)
                ->where('work_order_number', 'WO-DEMO-FIELD-001')
                ->first();

            $woId = $existingWo?->id ?? (string) Str::uuid7();

            DB::table('esm_work_orders')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'work_order_number' => 'WO-DEMO-FIELD-001'],
                [
                    'id' => $woId,
                    'company_id' => $tenant->id,
                    'customer_id' => $customerId,
                    'title' => 'Commercial HVAC Chiller Overhaul',
                    'description' => 'Compressor diagnostic and coolant recharge on site.',
                    'work_type' => 'corrective',
                    'priority' => 'high',
                    'status' => 'scheduled',
                    'scheduled_start' => now()->addDay()->setHour(9)->setMinute(0),
                    'scheduled_end' => now()->addDay()->setHour(14)->setMinute(0),
                    'labor_cost' => 350.00,
                    'material_cost' => 450.00,
                    'total_cost' => 800.00,
                    'total_billable_amount' => 1250.00,
                    'is_invoiced' => false,
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // 3. Scenario Industrial Maintenance: Preventive Asset Maintenance Work Order
            $existingIndWo = DB::table('esm_work_orders')
                ->where('tenant_id', $tenant->id)
                ->where('work_order_number', 'WO-DEMO-IND-001')
                ->first();

            $indWoId = $existingIndWo?->id ?? (string) Str::uuid7();

            DB::table('esm_work_orders')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'work_order_number' => 'WO-DEMO-IND-001'],
                [
                    'id' => $indWoId,
                    'company_id' => $tenant->id,
                    'customer_id' => $customerId,
                    'title' => 'Quarterly CNC Milling Calibration',
                    'description' => 'Precision spindle alignment and bearing lubrication.',
                    'work_type' => 'preventive',
                    'priority' => 'medium',
                    'status' => 'completed',
                    'actual_start' => now()->subDay()->setHour(8)->setMinute(0),
                    'actual_end' => now()->subDay()->setHour(12)->setMinute(0),
                    'resolution_notes' => 'Spindle aligned within 0.002mm tolerance. Oil filter replaced.',
                    'labor_cost' => 240.00,
                    'material_cost' => 120.00,
                    'total_cost' => 360.00,
                    'total_billable_amount' => 0.00,
                    'is_invoiced' => false,
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // 4. Scenario Professional Services: Change Request with CAB Approval
            $existingCr = DB::table('esm_change_requests')
                ->where('tenant_id', $tenant->id)
                ->where('change_number', 'CR-DEMO-PRO-001')
                ->first();

            $crId = $existingCr?->id ?? (string) Str::uuid7();

            DB::table('esm_change_requests')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'change_number' => 'CR-DEMO-PRO-001'],
                [
                    'id' => $crId,
                    'company_id' => $tenant->id,
                    'title' => 'PostgreSQL 17 Minor Cluster Upgrade',
                    'description' => 'Engine maintenance patch and connection pooler tuning.',
                    'change_type' => 'normal',
                    'risk_level' => 'low',
                    'status' => 'approved',
                    'impact_assessment' => 'Routine software service pack application',
                    'implementation_plan' => 'Failover replica node, upgrade primary, verify replication lag.',
                    'rollback_plan' => 'Revert failover to replica cluster snapshot.',
                    'cab_decision' => 'approved',
                    'is_locked' => false,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
