<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgQualityPlan;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * Enterprise Integrated Demo Environment Master Seeder.
 *
 * Populates tenant_demo ('Demo Manufacturing & Services Group') with master data
 * and executes end-to-end integrated cross-module transactions across Sales, Procurement,
 * Inventory, Manufacturing, Projects, Assets, Service Management, Subscriptions, and General Ledger.
 */
class EnterpriseDemoMasterSeeder extends Seeder
{
    public function run(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::firstOrCreate(
            ['name' => 'tenant_demo'],
            [
                'legal_name' => 'Demo Manufacturing & Services Group',
                'fiscal_code' => 'IT99887766554',
                'vat_number' => 'IT99887766554',
                'country' => 'IT',
                'default_currency' => 'EUR',
                'sdi_code' => 'DEMO999',
                'pec_email' => 'demo-group@pec.it',
                'iban' => 'IT02L1234512345000000123456',
                'settings' => [
                    'industry' => 'Advanced Discrete Manufacturing & Field Services',
                    'timezone' => 'Europe/Rome',
                    'erp_edition' => 'Enterprise Community Edition',
                ],
            ]
        );

        app(TenantContext::class)->setTenant($tenant);

        // 1. Run all domain-level demo seeders
        $this->call([
            CRMDemoDataSeeder::class,
            ProcurementDemoDataSeeder::class,
            InventoryDemoDataSeeder::class,
            AssetDemoDataSeeder::class,
            ProjectDemoDataSeeder::class,
            ServiceDemoDataSeeder::class,
            HRDemoDataSeeder::class,
            SubscriptionDemoDataSeeder::class,
            AccountingDemoDataSeeder::class,
            TreasuryDemoDataSeeder::class,
            ManufacturingDemoDataSeeder::class,
            DashboardLayoutSeeder::class,
        ]);

        // 2. Ensure Integrated Customer, Products & Warehouse Master Data for tenant_demo
        $warehouse = Warehouse::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Milano Plant Main Warehouse'],
            [
                'code' => 'WH-MIL-01',
                'is_active' => true,
                'address' => 'Via dell\'Industria 45, Milano',
            ]
        );

        $customer = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Acme Industrial Solutions SpA'],
            [
                'type' => 'customer',
                'vat_number' => 'IT01234987650',
                'fiscal_code' => '01234987650',
                'email' => 'procurement@acme-industrial.com',
                'phone' => '+39 02 88776655',
                'payment_terms' => 'NET_30',
                'is_active' => true,
            ]
        );

        $supplier = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Alloy & Steel Metals Europe Ltd'],
            [
                'type' => 'supplier',
                'vat_number' => 'IT05432198760',
                'fiscal_code' => '05432198760',
                'email' => 'orders@alloy-steel.eu',
                'phone' => '+39 011 55443322',
                'payment_terms' => 'NET_60',
                'is_active' => true,
            ]
        );

        $product = Product::firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => 'FG-PUMP-HD500'],
            [
                'name' => 'Heavy Duty Industrial Pump HD-500',
                'price' => 2450.00,
                'cost' => 1120.00,
                'vat_rate' => 22.0,
                'unit' => 'NR',
                'is_active' => true,
            ]
        );

        // 3. Integrated Sales Flow: Quote -> Sales Order -> Invoice -> Payment
        $quote = Quote::firstOrCreate(
            ['tenant_id' => $tenant->id, 'quote_number' => 'QT-DEMO-2026-001'],
            [
                'contact_id' => $customer->id,
                'date' => now()->toDateString(),
                'status' => 'accepted',
                'currency' => 'EUR',
                'total_amount' => 24500.00,
            ]
        );

        $salesOrder = SalesOrder::firstOrCreate(
            ['tenant_id' => $tenant->id, 'order_number' => 'SO-DEMO-2026-001'],
            [
                'contact_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'status' => 'confirmed',
                'currency' => 'EUR',
                'total_amount' => 24500.00,
            ]
        );

        $invoice = Invoice::firstOrCreate(
            ['tenant_id' => $tenant->id, 'invoice_number' => 'INV-DEMO-2026-001'],
            [
                'contact_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => 'paid',
                'type' => 'standard',
                'currency' => 'EUR',
                'total_amount' => 29890.00,
                'subtotal' => 24500.00,
                'tax_amount' => 5390.00,
            ]
        );

        Payment::firstOrCreate(
            ['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id],
            [
                'amount' => 29890.00,
                'payment_date' => now()->toDateString(),
                'payment_method' => 'BANK_TRANSFER',
                'status' => 'completed',
                'reference' => 'SEPA-CR-DEMO-2026-001',
            ]
        );

        // 4. Integrated Manufacturing Flow Setup
        $planningService = app(ProductionPlanningServiceInterface::class);
        $executionService = app(ProductionExecutionServiceInterface::class);
        $bomService = app(BillOfMaterialsServiceInterface::class);
        $routingService = app(RoutingServiceInterface::class);
        $qmService = app(QualityManagementServiceInterface::class);

        $rawMaterial = MfgItem::firstOrCreate(
            ['tenant_id' => $tenant->id, 'item_code' => 'RM-ST-CASTING'],
            [
                'name' => 'High Grade Stainless Casting 316L',
                'item_type' => 'raw_material',
                'standard_cost' => 120.00,
                'current_cost' => 120.00,
                'unit_of_measure' => 'KG',
                'lead_time_days' => 5,
            ]
        );

        $finishedGood = MfgItem::firstOrCreate(
            ['tenant_id' => $tenant->id, 'item_code' => 'FG-PUMP-HD500'],
            [
                'name' => 'Heavy Duty Industrial Pump HD-500',
                'item_type' => 'finished_good',
                'standard_cost' => 1120.00,
                'current_cost' => 1120.00,
                'unit_of_measure' => 'NR',
                'lead_time_days' => 7,
            ]
        );

        if (! $finishedGood->default_bom_id) {
            $bom = $bomService->createBom(new CreateBomDTO(
                tenantId: $tenant->id,
                mfgItemId: $finishedGood->id,
                name: 'HD-500 Standard Production BOM',
                components: [
                    ['component_item_id' => $rawMaterial->id, 'quantity_per' => 4.0, 'scrap_percentage' => 1.5],
                ]
            ));
            $finishedGood->update(['default_bom_id' => $bom->id]);
        }

        $workCenter = MfgWorkCenter::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'WC-MILL-01'],
            [
                'name' => 'Heavy Milling & Turning Station',
                'direct_labor_rate' => 45.00,
                'overhead_rate' => 25.00,
                'efficiency_percentage' => 95.0,
                'capacity_hours_per_day' => 16.0,
            ]
        );

        if (! $finishedGood->default_routing_id) {
            $routing = $routingService->createRouting(new CreateRoutingDTO(
                tenantId: $tenant->id,
                mfgItemId: $finishedGood->id,
                name: 'Standard Machining & Assembly Route',
                operations: [
                    [
                        'operation_number' => 10,
                        'name' => 'Rough and Finish Milling',
                        'work_center_id' => $workCenter->id,
                        'setup_time_minutes' => 30.0,
                        'run_time_per_unit_minutes' => 45.0,
                    ],
                ]
            ));
            $finishedGood->update(['default_routing_id' => $routing->id]);
        }

        // 5. Production Order execution & Quality verification
        $prodOrder = MfgProductionOrder::where('tenant_id', $tenant->id)
            ->where('mfg_item_id', $finishedGood->id)
            ->first();

        if (! $prodOrder) {
            $prodOrder = $planningService->planOrder(new CreateProductionOrderDTO(
                tenantId: $tenant->id,
                mfgItemId: $finishedGood->id,
                targetQuantity: 10.0,
                plannedStartDate: now()->toDateString(),
                plannedEndDate: now()->addDays(5)->toDateString(),
                notes: 'Integrated Demo Order for Acme Solutions'
            ));

            $prodOrder = $executionService->releaseOrder($prodOrder->id);
            $prodOrder = $executionService->startOrder($prodOrder->id);
        }

        // Quality Inspection
        $plan = MfgQualityPlan::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'QP-PUMP-FINAL'],
            [
                'mfg_item_id' => $finishedGood->id,
                'name' => 'Pump Hydrostatic & Dimensional Verification',
                'inspection_type' => 'final',
                'is_active' => true,
            ]
        );

        $qmService->recordInspection(new RecordQualityInspectionDTO(
            tenantId: $tenant->id,
            mfgQualityPlanId: $plan->id,
            productionOrderId: $prodOrder->id,
            sampleSize: 1,
            inspectorId: (string) Str::uuid(),
            overallResult: 'pass',
            measuredValues: [
                ['characteristic_id' => (string) Str::uuid(), 'measured_numeric' => 50.01, 'status' => 'pass'],
            ],
            notes: 'Demo Quality Inspection Hydrostatic Pressure 25 Bar Passed'
        ));
    }
}
