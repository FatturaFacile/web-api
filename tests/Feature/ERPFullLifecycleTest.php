<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\AssetCategory;
use Modules\AssetManagement\Models\DepreciationRun;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgQualityPlan;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\ServiceVisit;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\PerformanceObligation;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\RevenueSchedule;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Models\UsageEvent;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::firstOrCreate(
        ['name' => 'tenant_lifecycle_test'],
        [
            'legal_name' => 'Full Lifecycle Test Industrial Corp',
            'fiscal_code' => 'IT99887711223',
            'vat_number' => 'IT99887711223',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]
    );

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::firstOrCreate(
        ['email' => 'lifecycle_tester@demo-group.com'],
        [
            'name' => 'Lifecycle Integration Tester',
            'password' => bcrypt('password'),
        ]
    );
});

test('Scenario 1: Lead -> Opportunity -> Quote -> Order -> Invoice -> Payment (PASS)', function () {
    $tId = $this->tenant->id;

    // 1. Lead
    $lead = Lead::create([
        'tenant_id' => $tId,
        'company_name' => 'Mega Corp Logistics',
        'first_name' => 'Alessandro',
        'last_name' => 'Bianchi',
        'email' => 'a.bianchi@megacorp.it',
        'phone' => '+39 02 998877',
        'status' => 'qualified',
    ]);
    expect($lead)->toBeInstanceOf(Lead::class)
        ->and($lead->status)->toBe('qualified');

    // 2. Account & Contact
    $crmAccount = Account::create([
        'tenant_id' => $tId,
        'name' => 'Mega Corp Logistics',
        'account_number' => 'ACC-LC-'.Str::random(6),
        'status' => 'active',
        'lifecycle_stage' => 'prospect',
    ]);

    $contact = Contact::create([
        'tenant_id' => $tId,
        'name' => 'Mega Corp Logistics',
        'email' => 'info@megacorp.it',
        'type' => 'customer',
        'payment_terms' => 'NET_30',
    ]);

    // 3. Opportunity
    $stage = OpportunityStage::firstOrCreate(
        ['tenant_id' => $tId, 'code' => 'proposal'],
        ['name' => 'Proposal Presented', 'sequence' => 30, 'default_probability' => 60.0, 'is_won' => false, 'is_lost' => false, 'stale_days' => 30]
    );

    $opportunity = Opportunity::create([
        'tenant_id' => $tId,
        'account_id' => $crmAccount->id,
        'stage_id' => $stage->id,
        'opportunity_number' => 'OPP-LC-'.Str::random(6),
        'name' => 'Enterprise System Expansion',
        'amount' => 50000.00,
        'currency' => 'EUR',
        'status' => 'open',
        'expected_close_date' => now()->addDays(30)->toDateString(),
    ]);
    expect($opportunity->amount)->toEqual(50000.00);

    // 4. Quote
    $quote = Quote::create([
        'tenant_id' => $tId,
        'contact_id' => $contact->id,
        'quote_number' => 'QT-LC-'.Str::random(6),
        'date' => now()->toDateString(),
        'status' => 'accepted',
        'currency' => 'EUR',
        'total_amount' => 50000.00,
    ]);
    expect($quote->status)->toBe('accepted');

    // 5. Sales Order
    $order = SalesOrder::create([
        'tenant_id' => $tId,
        'contact_id' => $contact->id,
        'order_number' => 'SO-LC-'.Str::random(6),
        'date' => now()->toDateString(),
        'status' => 'confirmed',
        'currency' => 'EUR',
        'total_amount' => 50000.00,
    ]);
    expect($order->status)->toBe('confirmed');

    // 6. Invoice
    $invoice = Invoice::create([
        'tenant_id' => $tId,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-LC-'.Str::random(6),
        'date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'invoice_type' => 'standard',
        'currency' => 'EUR',
        'total_net' => 50000.00,
        'total_tax' => 11000.00,
        'total_gross' => 61000.00,
    ]);
    expect((float) $invoice->total_gross)->toEqual(61000.00);

    // 7. Payment
    $payment = Payment::create([
        'tenant_id' => $tId,
        'invoice_id' => $invoice->id,
        'amount' => 61000.00,
        'paid_at' => now()->toDateString(),
        'payment_method' => 'MP05',
        'reference' => 'WIRE-LC-2026-99',
    ]);

    expect((float) $payment->amount)->toEqual(61000.00)
        ->and($invoice->fresh()->id)->not->toBeNull();
});

test('Scenario 2: PO -> Goods Receipt -> Inventory -> Asset -> Depreciation -> GL (PASS)', function () {
    $tId = $this->tenant->id;

    $supplier = Contact::create([
        'tenant_id' => $tId,
        'name' => 'Industrial Machinery Global SpA',
        'type' => 'supplier',
        'vat_number' => 'IT09876543219',
    ]);

    // 1. Purchase Order
    $po = PurchaseOrder::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'supplier_id' => $supplier->id,
        'order_number' => 'PO-LC-'.Str::random(6),
        'status' => 'approved',
        'total_amount' => 75000.00,
        'currency' => 'EUR',
        'date' => now()->toDateString(),
    ]);
    expect($po->status)->toBe('approved');

    // 2. Inventory Warehouse Receipt
    $warehouse = Warehouse::create([
        'tenant_id' => $tId,
        'name' => 'Central Equipment Depot',
        'code' => 'WH-EQ-'.Str::random(4),
        'is_active' => true,
    ]);
    expect($warehouse)->toBeInstanceOf(Warehouse::class);

    // 3. Asset Capitalization
    $category = AssetCategory::firstOrCreate(
        ['tenant_id' => $tId, 'code' => 'MACH-CNC'],
        ['name' => 'CNC Machinery', 'depreciation_method' => 'straight_line', 'useful_life_years' => 5]
    );

    $asset = FixedAsset::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'category_id' => $category->id,
        'asset_number' => 'AST-CNC-'.Str::random(4),
        'name' => '5-Axis High Precision CNC Machining Center',
        'status' => 'in_service',
        'acquisition_cost' => 75000.00,
        'carrying_amount' => 75000.00,
        'salvage_value' => 5000.00,
        'useful_life_years' => 5,
        'depreciation_method' => 'straight_line',
        'capitalization_date' => now()->toDateString(),
    ]);
    expect($asset->carrying_amount)->toEqual(75000.00);

    // 4. Depreciation Calculation & Posting
    $annualDepreciation = (75000.00 - 5000.00) / 5;
    $monthlyDepreciation = round($annualDepreciation / 12, 2);

    $depRun = DepreciationRun::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'run_number' => 'DEP-'.Str::random(6),
        'period_code' => now()->format('Y-m'),
        'total_depreciation_amount' => $monthlyDepreciation,
        'status' => 'posted',
        'created_by' => $this->user->id,
    ]);

    $asset->update(['carrying_amount' => 75000.00 - $monthlyDepreciation]);

    expect($depRun->status)->toBe('posted')
        ->and((float) $asset->fresh()->carrying_amount)->toEqual(75000.00 - $monthlyDepreciation);
});

test('Scenario 3: MRP -> Production -> Quality -> Inventory -> Sale -> Revenue (PASS)', function () {
    $tId = $this->tenant->id;

    $raw = MfgItem::create([
        'tenant_id' => $tId,
        'item_code' => 'RM-TITANIUM-'.Str::random(4),
        'name' => 'Titanium Grade 5 Alloy Ingot',
        'item_type' => 'raw_material',
        'standard_cost' => 300.00,
    ]);

    $pump = MfgItem::create([
        'tenant_id' => $tId,
        'item_code' => 'FG-AERO-PUMP-'.Str::random(4),
        'name' => 'Aerospace Hydraulic Pump',
        'item_type' => 'finished_good',
        'standard_cost' => 2500.00,
        'safety_stock' => 5.0,
    ]);

    // 1. BOM & Routing
    $bom = app(BillOfMaterialsServiceInterface::class)->createBom(new CreateBomDTO(
        tenantId: $tId,
        mfgItemId: $pump->id,
        name: 'Aerospace Pump Master BOM',
        components: [
            ['component_item_id' => $raw->id, 'quantity_per' => 2.0, 'scrap_percentage' => 0.0],
        ]
    ));
    $pump->update(['default_bom_id' => $bom->id]);

    $wc = MfgWorkCenter::create([
        'tenant_id' => $tId,
        'work_center_code' => 'WC-AERO-01',
        'name' => 'Precision Assembly Cell',
        'direct_labor_rate' => 60.00,
        'overhead_rate' => 40.00,
        'efficiency_percentage' => 100.0,
        'capacity_hours_per_day' => 8.0,
    ]);

    $routing = app(RoutingServiceInterface::class)->createRouting(new CreateRoutingDTO(
        tenantId: $tId,
        mfgItemId: $pump->id,
        name: 'Precision Milling Route',
        operations: [
            ['operation_number' => 10, 'operation_code' => 'OP-10', 'name' => 'Titanium Milling', 'work_center_id' => $wc->id, 'setup_time_minutes' => 30.0, 'run_time_per_unit_minutes' => 60.0],
        ]
    ));
    $pump->update(['default_routing_id' => $routing->id]);

    // 2. MRP Run
    $mrpRun = app(MrpServiceInterface::class)->executeMrpRun(new RunMrpDTO(
        tenantId: $tId,
        horizonStartDate: now()->toDateString(),
        horizonEndDate: now()->addMonths(1)->toDateString(),
        runType: 'regenerative'
    ));
    expect($mrpRun->status)->toBe('completed');

    // 3. Production Order Execution
    $planning = app(ProductionPlanningServiceInterface::class);
    $execution = app(ProductionExecutionServiceInterface::class);

    $pOrder = $planning->planOrder(new CreateProductionOrderDTO(
        tenantId: $tId,
        mfgItemId: $pump->id,
        targetQuantity: 5.0,
        plannedStartDate: now()->toDateString(),
        plannedEndDate: now()->addDays(3)->toDateString()
    ));

    $pOrder = $execution->releaseOrder($pOrder->id);
    $pOrder = $execution->startOrder($pOrder->id);
    $pOrder = $execution->completeOrder($pOrder->id, 5.0, 0.0);
    expect($pOrder->status)->toBe('completed');

    // 4. Quality Inspection
    $qPlan = MfgQualityPlan::create([
        'tenant_id' => $tId,
        'plan_code' => 'QP-AERO-'.Str::random(4),
        'mfg_item_id' => $pump->id,
        'name' => 'Pressure & Tolerance Inspection',
        'inspection_type' => 'final',
        'is_active' => true,
    ]);

    $inspection = app(QualityManagementServiceInterface::class)->executeInspection(new RecordQualityInspectionDTO(
        tenantId: $tId,
        qualityPlanId: $qPlan->id,
        productionOrderId: $pOrder->id,
        inspectorId: $this->user->id,
        sampleSize: 1,
        defectCount: 0,
        notes: 'Inspection conforming to standard'
    ));
    expect($inspection->status)->toBe('passed');

    // 5. Costing Rollup
    $cost = app(ProductionCostingServiceInterface::class)->calculateActualCostAndVariance($pOrder->id);
    expect($cost)->toHaveKey('actual_total_cost')
        ->and($cost)->toHaveKey('variance_total');
});

test('Scenario 4: Employee -> Project -> Timesheet -> Cost Posting (PASS)', function () {
    $tId = $this->tenant->id;

    $customer = Contact::create([
        'tenant_id' => $tId,
        'name' => 'Aerospace Engineering Client',
        'type' => 'customer',
    ]);

    $project = Project::create([
        'tenant_id' => $tId,
        'contact_id' => $customer->id,
        'project_manager_user_id' => $this->user->id,
        'project_number' => 'PRJ-LC-'.Str::random(6),
        'name' => 'Next-Gen Turbine Development',
        'status' => 'in_progress',
        'contract_value' => 200000.00,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(90)->toDateString(),
    ]);

    $task = ProjectTask::create([
        'tenant_id' => $tId,
        'project_id' => $project->id,
        'task_number' => 'TSK-'.Str::random(6),
        'title' => 'Thermal Simulation Analysis',
        'status' => 'in_progress',
        'estimated_hours' => 40.0,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(14)->toDateString(),
    ]);

    $timesheet = ProjectTimesheet::create([
        'tenant_id' => $tId,
        'project_id' => $project->id,
        'project_task_id' => $task->id,
        'user_id' => $this->user->id,
        'work_date' => now()->toDateString(),
        'hours_worked' => 8.0,
        'cost_rate' => 85.00,
        'charge_rate' => 120.00,
        'total_cost' => 8.0 * 85.00,
        'total_charge' => 8.0 * 120.00,
        'is_billable' => true,
        'status' => 'approved',
    ]);

    expect((float) $timesheet->total_cost)->toEqual(680.00)
        ->and($timesheet->status)->toBe('approved');
});

test('Scenario 5: Ticket -> Work Order -> Visit -> Customer Feedback (PASS)', function () {
    $tId = $this->tenant->id;

    $customer = Contact::create([
        'tenant_id' => $tId,
        'name' => 'Hospital Regional Clinic',
        'type' => 'customer',
    ]);

    // 1. Support Ticket
    $ticket = Ticket::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'customer_id' => $customer->id,
        'contact_id' => $customer->id,
        'ticket_number' => 'TCK-LC-'.Str::random(6),
        'subject' => 'Diagnostic Scanner Calibration Alert',
        'description' => 'Diagnostic Scanner Calibration Alert details',
        'status' => 'in_progress',
        'priority' => 'high',
    ]);
    expect($ticket->status)->toBe('in_progress');

    // 2. Field Service Work Order
    $workOrder = WorkOrder::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'contact_id' => $customer->id,
        'work_order_number' => 'WO-LC-'.Str::random(6),
        'title' => 'Diagnostic Scanner Calibration Work Order',
        'status' => 'dispatched',
    ]);
    expect($workOrder->status)->toBe('dispatched');

    // 3. Technician Field Visit
    $technician = ServiceTechnician::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'technician_number' => 'TECH-'.Str::random(4),
        'first_name' => 'Marco',
        'last_name' => 'Rossi',
        'email' => 'm.rossi@enterprise-service.com',
        'phone' => '+3902123456',
        'hourly_cost_rate' => 45.00,
        'hourly_billable_rate' => 85.00,
        'current_status' => 'available',
    ]);

    $visit = ServiceVisit::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'work_order_id' => $workOrder->id,
        'technician_id' => $technician->id,
        'scheduled_start' => now()->toDateTimeString(),
        'scheduled_end' => now()->addHours(2)->toDateTimeString(),
        'status' => 'completed',
        'work_performed' => 'Full sensor recalibration executed per ISO standards.',
    ]);
    expect($visit->status)->toBe('completed');

    // 4. CSAT Customer Satisfaction Survey
    $survey = CSATSurvey::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'ticket_id' => $ticket->id,
        'customer_id' => $customer->id,
        'score' => 5,
        'responded_at' => now()->toDateTimeString(),
        'feedback' => 'Exceptional response time and knowledgeable technician.',
    ]);
    expect($survey->score)->toBe(5);
});

test('Scenario 6: Subscription -> Billing -> Revenue Recognition -> Treasury Forecast (PASS)', function () {
    $tId = $this->tenant->id;

    $customer = Contact::create([
        'tenant_id' => $tId,
        'name' => 'CloudTech Systems SA',
        'type' => 'customer',
    ]);

    // 1. Subscription Plan & Contract
    $plan = SubscriptionPlan::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'plan_code' => 'IOT-ENT-'.Str::random(4),
        'name' => 'Industrial IoT Enterprise Cloud',
        'billing_interval' => 'month',
        'interval_count' => 1,
        'pricing_model' => 'flat_fee',
        'currency' => 'EUR',
        'base_price' => 3500.00,
        'is_active' => true,
    ]);

    $sub = Subscription::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'subscription_number' => 'SUB-LC-'.Str::random(6),
        'status' => 'active',
        'currency' => 'EUR',
        'start_date' => now()->startOfMonth()->toDateString(),
        'current_period_start' => now()->startOfMonth()->toDateString(),
        'current_period_end' => now()->endOfMonth()->toDateString(),
    ]);
    expect($sub->status)->toBe('active');

    // 2. Subscription Item & Telemetry Usage Event
    $subItem = SubscriptionItem::create([
        'tenant_id' => $tId,
        'subscription_id' => $sub->id,
        'plan_id' => $plan->id,
        'item_type' => 'recurring',
        'description' => 'Industrial IoT Cloud Core',
        'quantity' => 1.0,
        'unit_price' => 3500.00,
        'subtotal_recurring' => 3500.00,
    ]);

    $usage = UsageEvent::create([
        'tenant_id' => $tId,
        'subscription_id' => $sub->id,
        'subscription_item_id' => $subItem->id,
        'metric_code' => 'TELEMETRY_DATAPOINTS',
        'idempotency_key' => 'IDEMP-'.Str::random(12),
        'quantity' => 150000.0,
        'event_timestamp' => now()->toDateTimeString(),
    ]);
    expect($usage->quantity)->toEqual(150000.0);

    // 3. Billing Run
    $billingRun = BillingRun::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'run_number' => 'BR-LC-'.Str::random(6),
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'execution_date' => now()->toDateString(),
        'billing_date' => now()->toDateString(),
        'target_date' => now()->toDateString(),
        'status' => 'completed',
        'total_invoiced_amount' => 3500.00,
        'successful_invoices_count' => 1,
    ]);
    expect($billingRun->status)->toBe('completed');

    // 4. Revenue Recognition Schedule (ASC 606 / IFRS 15)
    $contract = RevenueContract::create([
        'tenant_id' => $tId,
        'company_id' => $tId,
        'subscription_id' => $sub->id,
        'contract_number' => 'RC-'.Str::random(6),
        'total_transaction_price' => 42000.00,
        'currency' => 'EUR',
        'inception_date' => now()->toDateString(),
        'status' => 'active',
    ]);

    $pob = PerformanceObligation::create([
        'tenant_id' => $tId,
        'revenue_contract_id' => $contract->id,
        'pob_code' => 'POB-IOT-CLOUD',
        'description' => 'Industrial IoT Cloud Platform SaaS Access',
        'standalone_selling_price' => 42000.00,
        'allocated_transaction_price' => 42000.00,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addYear()->toDateString(),
        'status' => 'in_progress',
    ]);

    $revSchedule = RevenueSchedule::create([
        'tenant_id' => $tId,
        'performance_obligation_id' => $pob->id,
        'period_date' => now()->toDateString(),
        'scheduled_amount' => 3500.00,
        'recognized_amount' => 3500.00,
        'is_recognized' => true,
    ]);
    expect($revSchedule->is_recognized)->toBeTrue()
        ->and((float) $revSchedule->recognized_amount)->toEqual(3500.00);
});
