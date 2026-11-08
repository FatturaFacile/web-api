<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ProcurementDemoDataSeeder;
use Database\Seeders\ProcurementLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\PurchaseReturnLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierCategory;
use Modules\Procurement\Models\SupplierContact;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Models\SupplierEvaluation;
use Modules\Procurement\Models\SupplierPriceList;
use Modules\Procurement\Models\SupplierScorecard;
use Modules\Procurement\Models\ThreeWayMatchLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'name' => 'Procurement Test Tenant',
        'legal_name' => 'Procurement Enterprise Corp',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'name' => 'Procurement Officer',
        'email' => 'officer@procurement.test',
        'password' => bcrypt('password123'),
    ]);

    if (! $this->user->belongsToTenant($this->tenant->id)) {
        $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    }

    Sanctum::actingAs($this->user);
});

test('idempotent seeders execute cleanly and populate lookups and demo records', function (): void {
    $this->seed(ProcurementLookupSeeder::class);
    $this->seed(ProcurementDemoDataSeeder::class);

    expect(Supplier::count())->toBeGreaterThanOrEqual(1);
    expect(PurchaseOrder::count())->toBeGreaterThanOrEqual(1);
    expect(PurchaseRequisition::count())->toBeGreaterThanOrEqual(1);
});

test('all 17 procurement factories instantiate and persist with relationships and custom states', function (): void {
    $category = SupplierCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    expect($category)->toBeInstanceOf(SupplierCategory::class);

    $supplier = Supplier::factory()->approved()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_category_id' => $category->id,
    ]);
    expect($supplier->is_approved_vendor)->toBeTrue();

    $contact = SupplierContact::factory()->primary()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
    ]);
    expect($contact->is_primary)->toBeTrue();

    $catalog = SupplierCatalog::factory()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
    ]);
    expect($catalog)->toBeInstanceOf(SupplierCatalog::class);

    $priceList = SupplierPriceList::factory()->tiered(50.0, 19.99)->create([
        'tenant_id' => $this->tenant->id,
        'supplier_catalog_id' => $catalog->id,
    ]);
    expect((float) $priceList->unit_price)->toBe(19.99);

    $contract = SupplierContract::factory()->framework()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
    ]);
    expect($contract->contract_type)->toBe('framework');

    $requisition = PurchaseRequisition::factory()->approved()->create([
        'tenant_id' => $this->tenant->id,
        'requestor_user_id' => $this->user->id,
    ]);
    expect($requisition->status)->toBe('approved');

    $prLine = PurchaseRequisitionLine::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_requisition_id' => $requisition->id,
    ]);
    expect($prLine)->toBeInstanceOf(PurchaseRequisitionLine::class);

    $po = PurchaseOrder::factory()->approved()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'purchase_requisition_id' => $requisition->id,
        'supplier_contract_id' => $contract->id,
    ]);
    expect($po->status)->toBe('approved');

    $poLine = PurchaseOrderLine::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $po->id,
    ]);
    expect($poLine)->toBeInstanceOf(PurchaseOrderLine::class);

    $receipt = PurchaseReceipt::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $po->id,
        'received_by_user_id' => $this->user->id,
    ]);
    expect($receipt)->toBeInstanceOf(PurchaseReceipt::class);

    $receiptLine = PurchaseReceiptLine::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_receipt_id' => $receipt->id,
        'purchase_order_line_id' => $poLine->id,
    ]);
    expect($receiptLine)->toBeInstanceOf(PurchaseReceiptLine::class);

    $return = PurchaseReturn::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $po->id,
        'supplier_id' => $supplier->id,
        'returned_by_user_id' => $this->user->id,
    ]);
    expect($return)->toBeInstanceOf(PurchaseReturn::class);

    $returnLine = PurchaseReturnLine::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_return_id' => $return->id,
        'purchase_order_line_id' => $poLine->id,
    ]);
    expect($returnLine)->toBeInstanceOf(PurchaseReturnLine::class);

    $matchLog = ThreeWayMatchLog::factory()->priceVariance(75.0)->create([
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $po->id,
        'purchase_receipt_id' => $receipt->id,
    ]);
    expect($matchLog->match_status)->toBe('price_variance');

    $eval = SupplierEvaluation::factory()->excellent()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'evaluator_user_id' => $this->user->id,
    ]);
    expect($eval->overall_rating)->toBe('A');

    $scorecard = SupplierScorecard::factory()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
    ]);
    expect($scorecard)->toBeInstanceOf(SupplierScorecard::class);
});

test('supplier REST API supports full CRUD lifecycle with pagination and sorting', function (): void {
    // 1. Index
    $response = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->getJson('/api/v1/procurement/suppliers?page=1&per_page=10&sort=company_name');
    $response->assertOk();
    $response->assertJsonStructure(['data', 'meta']);

    // 2. Store
    $uniqueCode = 'SUP-TST-'.uniqid();
    $storeResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson('/api/v1/procurement/suppliers', [
            'supplier_code' => $uniqueCode,
            'company_name' => 'Test Supplier Enterprise LLC',
            'vat_number' => 'IT99887766554',
            'currency' => 'EUR',
            'payment_terms_code' => 'NET30',
            'is_approved_vendor' => true,
        ]);
    $storeResponse->assertCreated();
    $supplierId = $storeResponse->json('id');

    // 3. Show
    $showResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->getJson("/api/v1/procurement/suppliers/{$supplierId}");
    $showResponse->assertOk();
    expect($showResponse->json('supplier_code'))->toBe($uniqueCode);

    // 4. Update
    $updateResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->putJson("/api/v1/procurement/suppliers/{$supplierId}", [
            'credit_limit' => 125000.00,
            'rating' => 'A',
        ]);
    $updateResponse->assertOk();
    expect((float) $updateResponse->json('credit_limit'))->toBe(125000.00);

    // 5. Delete
    $deleteResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->deleteJson("/api/v1/procurement/suppliers/{$supplierId}");
    $deleteResponse->assertOk();
    expect(Supplier::find($supplierId))->toBeNull();
});

test('purchase order lifecycle endpoints operate seamlessly', function (): void {
    fiscalCalendar($this->tenant, (int) now()->year);
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);

    // Create PO
    $poResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson('/api/v1/procurement/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'lines' => [
                [
                    'description' => 'Industrial Hydraulic Valves',
                    'quantity_ordered' => 20,
                    'unit_price' => 150.00,
                    'uom' => 'PCS',
                ],
            ],
        ]);
    $poResponse->assertCreated();
    $poId = $poResponse->json('id');

    // Submit for approval
    $submitResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson("/api/v1/procurement/purchase-orders/{$poId}/submit-approval");
    // Without an approval matrix or a configured workflow the order needs no approval.
    $submitResponse->assertOk();
    expect($submitResponse->json('status'))->toBe('approved');

    // Close
    $closeResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson("/api/v1/procurement/purchase-orders/{$poId}/close");
    $closeResponse->assertOk();
    expect($closeResponse->json('status'))->toBe('closed');

    // Reopen
    $reopenResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson("/api/v1/procurement/purchase-orders/{$poId}/reopen");
    $reopenResponse->assertOk();
    expect($reopenResponse->json('status'))->toBe('approved');
});

test('purchase requisition lifecycle endpoints operate seamlessly', function (): void {
    // 1. Create draft PR
    $prResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson('/api/v1/procurement/requisitions', [
            'title' => 'Server Room Cooling Units',
            'required_by_date' => now()->addDays(20)->toDateString(),
            'urgency' => 'high',
            'lines' => [
                [
                    'description' => 'Precision In-Row Air Conditioner',
                    'quantity_requested' => 2,
                    'estimated_unit_price' => 4500.00,
                    'uom' => 'PCS',
                ],
            ],
        ]);
    $prResponse->assertCreated();
    $prId = $prResponse->json('id');

    // 2. Submit for approval
    $submitResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson("/api/v1/procurement/requisitions/{$prId}/submit-approval");
    // Without an approval matrix or a configured workflow the requisition needs no approval.
    $submitResponse->assertOk();
    expect($submitResponse->json('status'))->toBe('approved');

    // 3. A manual decision is only for a requisition waiting for approval
    $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson("/api/v1/procurement/requisitions/{$prId}/approve")
        ->assertUnprocessable();
});

test('three-way matching and evaluations endpoints operate cleanly', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $po = PurchaseOrder::factory()->approved()->create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'net_amount' => 1000.00,
        'tax_amount' => 220.00,
        'total_amount' => 1220.00,
    ]);
    $poLine = PurchaseOrderLine::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $po->id,
        'quantity_ordered' => 10,
        'unit_price' => 100.00,
        'line_total_amount' => 1220.00,
    ]);
    $receipt = PurchaseReceipt::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_order_id' => $po->id,
        'received_by_user_id' => $this->user->id,
    ]);
    PurchaseReceiptLine::factory()->create([
        'tenant_id' => $this->tenant->id,
        'purchase_receipt_id' => $receipt->id,
        'purchase_order_line_id' => $poLine->id,
        'quantity_received' => 10,
        'quantity_accepted' => 10,
    ]);

    // Three-way match execute: the invoice of the supplier for the goods received
    $invoice = PassiveInvoice::withoutGlobalScopes()->create([
        'tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'FT-1', 'date' => now()->toDateString(),
        'total_net' => '1000.00', 'total_tax' => '220.00', 'total_amount' => '1220.00', 'currency' => 'EUR', 'document_type' => 'TD01', 'payment_status' => 'unpaid', 'total_paid' => 0,
    ]);
    $matchResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson('/api/v1/procurement/three-way-match/execute', [
            'purchase_order_id' => $po->id,
            'purchase_receipt_id' => $receipt->id,
            'passive_invoice_id' => $invoice->id,
            'invoice_total_amount' => 1220.00,
        ]);
    $matchResponse->assertCreated();
    expect($matchResponse->json('match_status'))->toBe('matched');

    // Supplier evaluation submission
    $evalResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->postJson("/api/v1/procurement/suppliers/{$supplier->id}/evaluations", [
            'evaluation_period_start' => '2026-01-01',
            'evaluation_period_end' => '2026-03-31',
            'on_time_delivery_rate' => 96.5,
            'quality_acceptance_rate' => 99.0,
            'price_competitiveness_score' => 92.0,
            'overall_rating' => 'A',
            'is_approved_vendor' => true,
            'comments' => 'High delivery reliability',
        ]);
    $evalResponse->assertCreated();

    // Supplier scorecard retrieval
    $scorecardResponse = $this->withHeader('X-Tenant-Id', $this->tenant->id)
        ->getJson("/api/v1/procurement/suppliers/{$supplier->id}/scorecard?year=2026&quarter=1");
    $scorecardResponse->assertOk();
    expect($scorecardResponse->json('supplier_id'))->toBe($supplier->id);
});
