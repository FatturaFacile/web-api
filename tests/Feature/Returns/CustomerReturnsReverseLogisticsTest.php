<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\Supplier;
use Modules\Returns\Models\AdvanceReplacement;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\RefurbishedItem;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = Tenant::create([
        'name' => 'Reverse Co '.Str::random(4),
        'legal_name' => 'Reverse Co Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $this->owner = User::create([
        'name' => 'Owner '.Str::random(4),
        'email' => Str::random(8).'@mod232.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'en',
    ]);
    $this->owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    app(TenantContext::class)->setTenant($this->tenant);
    Sanctum::actingAs($this->owner);
    $this->withHeaders(['X-Tenant-Id' => (string) $this->tenant->id]);

    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Cliente Test Srl',
        'country' => 'IT',
    ]);
    $this->primaryWarehouse = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'MAIN',
        'name' => 'Primary Warehouse',
        'is_primary' => true,
    ]);
    $this->outletWarehouse = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'OUTLET',
        'name' => 'Outlet Warehouse',
        'is_primary' => false,
    ]);
    $this->product = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'LAPTOP-X1',
        'name' => 'Laptop Pro X1',
        'cost_price' => 500.00,
        'sale_price' => 1000.00,
        'tax_rate' => 22.00,
        'track_inventory' => true,
    ]);
    app(StockService::class)->record((string) $this->product->id, 'in', 10.0, null, $this->primaryWarehouse, 500.00);
});

afterEach(fn () => app(TenantContext::class)->clear());

it('evaluates eligibility, calculates restocking fees for commercial returns, and generates RMA barcode and label', function (): void {
    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-2027-100',
        'date' => '2027-06-05',
        'total_net' => 1000.00,
        'total_tax' => 220.00,
        'total_gross' => 1220.00,
    ]);
    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => $this->product->id,
        'description' => 'Laptop Pro X1',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => 22.00,
        'line_total' => 1000.00,
    ]);

    // Commercial return -> 10% restocking fee
    $eligibility = $this->postJson('/api/v1/returns/eligibility-check', [
        'contact_id' => $this->contact->id,
        'invoice_id' => $invoice->id,
        'reason_code' => 'right_of_withdrawal',
        'lines' => [['invoice_item_id' => $item->id, 'quantity' => 1]],
    ])->assertOk()->json('data');

    expect($eligibility['eligible'])->toBeTrue()
        ->and($eligibility['restocking_fee_applicable'])->toBeTrue()
        ->and($eligibility['restocking_fee_amount'])->toBe('100.00')
        ->and($eligibility['net_refundable_amount'])->toBe('900.00');

    // DOA -> 0% restocking fee
    $doaEligibility = $this->postJson('/api/v1/returns/eligibility-check', [
        'contact_id' => $this->contact->id,
        'invoice_id' => $invoice->id,
        'reason_code' => 'doa',
        'lines' => [['invoice_item_id' => $item->id, 'quantity' => 1]],
    ])->assertOk()->json('data');

    expect($doaEligibility['restocking_fee_applicable'])->toBeFalse()
        ->and($doaEligibility['restocking_fee_amount'])->toBe('0.00');

    // Create self-service portal RMA
    $created = $this->postJson('/api/v1/returns/customer-returns', [
        'contact_id' => $this->contact->id,
        'invoice_id' => $invoice->id,
        'reason_code' => 'right_of_withdrawal',
        'channel' => 'portal_b2c',
        'photo_urls' => ['https://cdn.test/photo1.jpg'],
        'lines' => [['invoice_item_id' => $item->id, 'quantity' => 1]],
    ])->assertCreated()->json('data');

    expect($created['rma_barcode'])->toStartWith('RMA-BC-')
        ->and($created['net_refundable_amount'])->toBe('900.00');

    $label = $this->getJson("/api/v1/returns/customer-returns/{$created['id']}/label")->assertOk()->json('data');
    expect($label['rma_barcode'])->toBe($created['rma_barcode'])
        ->and($label['barcode_format'])->toBe('CODE128');
});

it('manages advance replacement cross-shipping and bills overdue penalty invoice after SLA window', function (): void {
    $replacement = $this->postJson('/api/v1/returns/advance-replacements', [
        'contact_id' => $this->contact->id,
        'faulty_product_id' => $this->product->id,
        'replacement_unit_type' => 'new',
        'quantity' => 1,
        'shipped_on' => '2027-05-01',
        'sla_days' => 15,
        'penalty_amount' => 1000.00,
    ])->assertCreated()->json('data');

    expect($replacement['status'])->toBe(AdvanceReplacement::StatusPendingDefectiveReturn)
        ->and($replacement['return_due_on'])->toBe('2027-05-16')
        ->and($replacement['is_overdue'])->toBeTrue();

    // Overdue scan bills penalty invoice
    $this->postJson('/api/v1/returns/advance-replacements/scan-overdue')
        ->assertOk()
        ->assertJsonPath('data.billed_count', 1);

    $updated = AdvanceReplacement::query()->findOrFail($replacement['id']);
    expect($updated->status)->toBe(AdvanceReplacement::StatusOverdueBilled)
        ->and($updated->penalty_invoice_id)->not->toBeNull();
});

it('requires mandatory photos for grading, converts Grade B to B-Stock SKU with devaluation, and drafts RTV for scrap/supplier', function (): void {
    // Missing photo_urls must fail validation
    $this->postJson('/api/v1/returns/grading-inspections', [
        'product_id' => $this->product->id,
        'quantity_inspected' => 1,
        'grade' => 'grade_b',
        'disposition' => 'convert_b_stock',
        'photo_urls' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('photo_urls');

    // Grade B -> B-Stock SKU with 20% devaluation
    $gradeB = $this->postJson('/api/v1/returns/grading-inspections', [
        'product_id' => $this->product->id,
        'quantity_inspected' => 1,
        'grade' => 'grade_b',
        'disposition' => 'convert_b_stock',
        'photo_urls' => ['https://cdn.test/grade-b.jpg'],
        'checklist' => ['package_intact' => false, 'functional_test_passed' => true],
    ])->assertCreated()->json('data');

    expect($gradeB['devaluation_percent'])->toBe('20.00')
        ->and($gradeB['revalued_unit_cost'])->toBe('400.0000')
        ->and($gradeB['refurbished_sku'])->toContain('LAPTOP-X1-BSTOCK-B');

    $refurbishedId = $gradeB['refurbished_item_id'];
    $this->postJson("/api/v1/returns/refurbished-items/{$refurbishedId}/complete-work-order", [
        'work_order_status' => RefurbishedItem::WorkOrderCompleted,
        'refurbishment_cost' => 25.00,
    ])->assertOk()->assertJsonPath('data.new_unit_cost', '425.0000');

    // Return to supplier
    $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    $rtvInspection = $this->postJson('/api/v1/returns/grading-inspections', [
        'product_id' => $this->product->id,
        'quantity_inspected' => 1,
        'grade' => 'scrap_or_supplier',
        'disposition' => 'return_to_supplier',
        'supplier_id' => $supplier->id,
        'photo_urls' => ['https://cdn.test/rtv.jpg'],
    ])->assertCreated()->json('data');

    expect($rtvInspection['supplier_return_id'])->not->toBeNull();
});

it('enforces Art. 26 DPR 633/72 one-year VAT time limit with N2.2 proposal and Art. 41 LIVA traceability', function (): void {
    // Invoice > 1 year old (2026-01-10 vs 2027-06-15)
    $oldInvoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-2026-001',
        'jurisdiction' => 'IT',
        'date' => '2026-01-10',
        'total_net' => 1000.00,
        'total_tax' => 220.00,
        'total_gross' => 1220.00,
    ]);
    $oldItem = InvoiceItem::create([
        'invoice_id' => $oldInvoice->id,
        'product_id' => $this->product->id,
        'description' => 'Laptop Pro X1',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => 22.00,
        'line_total' => 1000.00,
    ]);

    $return = $this->postJson('/api/v1/returns/customer-returns', [
        'contact_id' => $this->contact->id,
        'invoice_id' => $oldInvoice->id,
        'reason_code' => 'commercial_return',
        'override_window' => true,
        'lines' => [['invoice_item_id' => $oldItem->id, 'quantity' => 1]],
    ])->assertCreated()->json('data');

    $check = $this->postJson('/api/v1/returns/credit-note-check', [
        'customer_return_id' => $return['id'],
        'credit_note_date' => '2027-06-15',
    ])->assertOk()->json('data');

    expect($check['art26_one_year_exceeded'])->toBeTrue()
        ->and($check['vat_recoverable'])->toBeFalse()
        ->and($check['proposed_natura'])->toBe('N2.2')
        ->and($check['totals']['vat'])->toBe('0.00');

    $issued = $this->postJson('/api/v1/returns/credit-notes', [
        'customer_return_id' => $return['id'],
        'date' => '2027-06-15',
        'apply_n2_2' => true,
    ])->assertCreated()->json('data');

    expect($issued['document_type'])->toBe('TD04')
        ->and($issued['vat_nature'])->toBe('N2.2')
        ->and($issued['total_tax'])->toBe('0.00');
});

it('isolates customer returns across tenants', function (): void {
    $otherTenant = Tenant::create([
        'name' => 'Other Co '.Str::random(4),
        'legal_name' => 'Other Co Srl',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);
    $otherUser = User::create([
        'name' => 'Other '.Str::random(4),
        'email' => Str::random(8).'@other.example',
        'password' => bcrypt(Str::random(32)),
        'locale' => 'en',
    ]);
    $otherUser->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    $return = CustomerReturn::create([
        'tenant_id' => $this->tenant->id,
        'rma_number' => 'CRMA-2027-9999',
        'rma_barcode' => 'RMA-BC-TEST',
        'contact_id' => $this->contact->id,
        'reason_code' => 'doa',
        'requested_on' => '2027-06-15',
    ]);

    app(TenantContext::class)->setTenant($otherTenant);
    Sanctum::actingAs($otherUser);
    $this->withHeaders(['X-Tenant-Id' => (string) $otherTenant->id]);

    $this->getJson("/api/v1/returns/customer-returns/{$return->id}")->assertNotFound();
    $this->getJson('/api/v1/returns/customer-returns')->assertOk()->assertJsonCount(0, 'data');
});
