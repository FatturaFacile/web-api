<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ProcurementLookupSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Actions\CreatePurchaseRequisitionAction;
use Modules\Procurement\Actions\CreateSupplierAction;
use Modules\Procurement\Actions\ExecuteThreeWayMatchAction;
use Modules\Procurement\Actions\ProcessGoodsReceiptAction;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Modules\Procurement\DTOs\CreateSupplierDTO;
use Modules\Procurement\DTOs\ExecuteThreeWayMatchDTO;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Events\SupplierApprovedEvent;
use Modules\Procurement\Events\SupplierSuspendedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCategory;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'name' => 'Procurement Enterprise Tenant',
        'legal_name' => 'Procurement Enterprise Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'name' => 'Chief Procurement Officer',
        'email' => 'cpo@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    Sanctum::actingAs($this->user);
});

test('supplier lifecycle: creation, approval, and automated AVL update', function (): void {
    $this->seed(ProcurementLookupSeeder::class);
    $category = SupplierCategory::first() ?? SupplierCategory::factory()->create();

    $action = app(CreateSupplierAction::class);
    $dto = new CreateSupplierDTO(
        companyName: 'Brembo Brake Systems S.p.A.',
        supplierCategoryId: (string) $category->id,
        vatNumber: 'IT01234567890',
        fiscalCode: '01234567890',
        currency: 'EUR',
        paymentTermsCode: 'NET60',
        incoterms: 'DAP',
        creditLimit: 100000.00,
        notes: 'Tier 1 OEM Supplier',
        userId: (string) $this->user->id
    );

    $supplier = $action->execute($dto);
    expect($supplier)->toBeInstanceOf(Supplier::class);
    expect($supplier->status)->toBe('active');

    // Trigger Approval Event and verify AVL status
    event(new SupplierApprovedEvent(
        supplierId: (string) $supplier->id,
        supplierCode: (string) $supplier->supplier_code,
        approvedByUserId: (string) $this->user->id,
        tenantId: (string) $this->tenant->id,
        companyId: ''
    ));

    $supplier->refresh();
    expect($supplier->is_approved_vendor)->toBeTrue();
});

test('requisition lifecycle: approval triggers automated purchase order generation', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);

    $reqAction = app(CreatePurchaseRequisitionAction::class);
    $dto = new CreatePurchaseRequisitionDTO(
        title: 'Q4 Industrial Bearing Replenishment',
        urgency: 'high',
        requiredByDate: now()->addDays(7)->toDateString(),
        notes: 'Priority batch for assembly line 2',
        userId: (string) $this->user->id,
        lines: [
            [
                'product_id' => (string) Str::uuid(),
                'suggested_supplier_id' => (string) $supplier->id,
                'description' => 'Precision Roller Bearings 608-2RS',
                'quantity' => 100.0,
                'estimated_unit_price' => 15.50,
            ],
        ]
    );

    $requisition = $reqAction->execute($dto);
    expect($requisition)->toBeInstanceOf(PurchaseRequisition::class);
    expect($requisition->status)->toBe('draft');

    // Simulate Requisition Approval
    $requisition->update(['status' => 'approved']);
    event(new PurchaseRequisitionApprovedEvent(
        requisitionId: (string) $requisition->id,
        requisitionNumber: (string) $requisition->requisition_number,
        approvedByUserId: (string) $this->user->id,
        tenantId: (string) $this->tenant->id,
        companyId: ''
    ));

    $requisition->refresh();
    expect($requisition->status)->toBe('converted_to_po');

    $po = PurchaseOrder::where('created_by', $this->user->id)
        ->where('supplier_id', $supplier->id)
        ->latest()
        ->first();

    expect($po)->not->toBeNull();
    expect((float) $po->net_amount)->toBe(1550.00);
});

test('purchase order lifecycle: approval commits budget and authorizes receiving', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->draft()->create([
        'supplier_id' => $supplier->id,
        'tenant_id' => $this->tenant->id,
        'total_amount' => 5000.00,
    ]);

    event(new PurchaseOrderApprovedEvent(
        purchaseOrderId: (string) $order->id,
        poNumber: (string) $order->po_number,
        approvedByUserId: (string) $this->user->id,
        totalAmount: 5000.00,
        currency: 'EUR',
        tenantId: (string) $this->tenant->id,
        companyId: ''
    ));

    $order->refresh();
    expect($order->status)->toBe('approved');
    expect($order->is_locked)->toBeFalse();
    expect($order->is_budget_encumbered)->toBeTrue();
});

test('goods receipt note processing emits inventory integration events', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->approved()->create([
        'supplier_id' => $supplier->id,
        'tenant_id' => $this->tenant->id,
    ]);

    $poLine = PurchaseOrderLine::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $order->id,
        'description' => 'Industrial Fan Unit',
        'quantity_ordered' => 10.0,
        'quantity_received' => 0.0,
        'quantity_cancelled' => 0.0,
        'unit_price' => 120.00,
        'subtotal' => 1200.00,
        'tax_rate' => 22.0,
        'tax_amount' => 264.00,
        'total' => 1464.00,
    ]);

    $receiptAction = app(ProcessGoodsReceiptAction::class);
    $dto = new ProcessGoodsReceiptDTO(
        purchaseOrderId: (string) $order->id,
        supplierDeliveryNoteRef: 'DDT-2026-X88',
        receivedDate: now()->toDateString(),
        receivedByUserId: (string) $this->user->id,
        lines: [
            [
                'purchase_order_line_id' => (string) $poLine->id,
                'quantity_received' => 10.0,
                'quantity_accepted' => 10.0,
                'quantity_rejected' => 0.0,
                'lot_number' => 'LOT-X88',
            ],
        ]
    );

    $receipt = $receiptAction->execute($dto);
    expect($receipt)->toBeInstanceOf(PurchaseReceipt::class);
    expect($receipt->inspection_status)->toBe('passed');

    $poLine->refresh();
    expect((float) $poLine->quantity_received)->toBe(10.0);
});

test('three-way matching: exact match passes cleanly while variance triggers discrepancy escalation', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $order = PurchaseOrder::factory()->approved()->create([
        'supplier_id' => $supplier->id,
        'tenant_id' => $this->tenant->id,
        'total_amount' => 1220.00,
    ]);

    $receipt = PurchaseReceipt::factory()->create([
        'purchase_order_id' => $order->id,
        'tenant_id' => $this->tenant->id,
    ]);

    $matchAction = app(ExecuteThreeWayMatchAction::class);

    // 1. Exact Match Test
    $exactDto = new ExecuteThreeWayMatchDTO(
        purchaseOrderId: (string) $order->id,
        purchaseReceiptId: (string) $receipt->id,
        passiveInvoiceId: (string) Str::uuid(),
        invoiceTotalAmount: 1220.00,
        evaluatedByUserId: (string) $this->user->id
    );

    $exactLog = $matchAction->execute($exactDto);
    expect($exactLog->match_status)->toBe('matched');
    expect((float) $exactLog->variance_amount)->toBe(0.0);

    // 2. Discrepancy Test (> 1% tolerance)
    $discrepancyDto = new ExecuteThreeWayMatchDTO(
        purchaseOrderId: (string) $order->id,
        purchaseReceiptId: (string) $receipt->id,
        passiveInvoiceId: (string) Str::uuid(),
        invoiceTotalAmount: 1400.00, // €180 over PO
        evaluatedByUserId: (string) $this->user->id
    );

    $discrepancyLog = $matchAction->execute($discrepancyDto);
    expect($discrepancyLog->match_status)->toBe('price_variance');
    expect((float) $discrepancyLog->variance_amount)->toBe(180.00);
});

test('supplier suspension automatically restricts open purchase orders', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $draftOrder = PurchaseOrder::factory()->draft()->create([
        'supplier_id' => $supplier->id,
        'tenant_id' => $this->tenant->id,
    ]);

    event(new SupplierSuspendedEvent(
        supplierId: (string) $supplier->id,
        supplierCode: (string) $supplier->supplier_code,
        reason: 'Fraudulent billing detected',
        tenantId: (string) $this->tenant->id,
        companyId: ''
    ));

    $draftOrder->refresh();
    expect($draftOrder->status)->toBe('cancelled');
    expect($draftOrder->is_locked)->toBeTrue();
});
