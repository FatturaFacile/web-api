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
            ['tenant_id' => $tenant->id, 'company_name' => 'Acme Industrial Solutions SpA'],
            [
                'type' => 'client',
                'vat_number' => 'IT01234987650',
                'fiscal_code' => '01234987650',
                'email' => 'procurement@acme-industrial.com',
                'phone' => '+39 02 88776655',
                'payment_terms_days' => 30,
            ]
        );

        $supplier = Contact::firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_name' => 'Alloy & Steel Metals Europe Ltd'],
            [
                'type' => 'supplier',
                'vat_number' => 'IT05432198760',
                'fiscal_code' => '05432198760',
                'email' => 'orders@alloy-steel.eu',
                'phone' => '+39 011 55443322',
                'payment_terms_days' => 60,
            ]
        );

        $product = Product::firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => 'FG-PUMP-HD500'],
            [
                'name' => 'Heavy Duty Industrial Pump HD-500',
                'sale_price' => 2450.00,
                'cost_price' => 1120.00,
                'tax_rate' => 22.0,
                'unit_measure' => 'NR',
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
                'total_net' => 24500.00,
                'total_tax' => 5390.00,
                'total_gross' => 29890.00,
            ]
        );

        $salesOrder = SalesOrder::firstOrCreate(
            ['tenant_id' => $tenant->id, 'order_number' => 'SO-DEMO-2026-001'],
            [
                'contact_id' => $customer->id,
                'order_date' => now()->toDateString(),
                'status' => 'confirmed',
                'currency' => 'EUR',
                'total_net' => 24500.00,
                'total_tax' => 5390.00,
                'total_gross' => 29890.00,
            ]
        );

        $invoice = Invoice::firstOrCreate(
            ['tenant_id' => $tenant->id, 'invoice_number' => 'INV-DEMO-2026-001'],
            [
                'contact_id' => $customer->id,
                'invoice_date' => now()->toDateString(),
                'payment_status' => 'paid',
                'invoice_type' => 'standard',
                'currency' => 'EUR',
                'total_net' => 24500.00,
                'total_tax' => 5390.00,
                'total_gross' => 29890.00,
                'total_paid' => 29890.00,
            ]
        );

        Payment::firstOrCreate(
            ['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id],
            [
                'amount' => 29890.00,
                'paid_at' => now()->toDateString(),
                'payment_method' => 'MP05',
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
                'uom' => 'KG',
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
                'uom' => 'NR',
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
            ['tenant_id' => $tenant->id, 'work_center_code' => 'WC-MILL-01'],
            [
                'name' => 'Heavy Milling & Turning Station',
                'hourly_cost_rate' => 45.00,
                'hourly_overhead_rate' => 25.00,
                'efficiency_rate' => 95.0,
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
            ['tenant_id' => $tenant->id, 'plan_code' => 'QP-PUMP-FINAL'],
            [
                'mfg_item_id' => $finishedGood->id,
                'name' => 'Pump Hydrostatic & Dimensional Verification',
                'inspection_type' => 'final',
                'is_active' => true,
            ]
        );

        $qmService->executeInspection(new RecordQualityInspectionDTO(
            tenantId: $tenant->id,
            qualityPlanId: $plan->id,
            productionOrderId: $prodOrder->id,
            sampleSize: 1,
            defectCount: 0,
            notes: 'Demo Quality Inspection Hydrostatic Pressure 25 Bar Passed'
        ));
    }
}
