<?php

declare(strict_types=1);

use App\Models\DocumentSequence;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\SupplierContract;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

require_once __DIR__.'/ProcurementEnterpriseFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = procTenant('Acquisti Alfa');
    $this->otherTenant = procTenant('Acquisti Beta');
    $this->buyer = procMember($this->tenant, 'admin');
    $this->owner = procMember($this->tenant, 'owner');
    procActAs($this->buyer, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('purchase orders of the module and of the purchasing API share one gapless series', function (): void {
    $supplierId = procSupplier();
    $year = now()->year;

    $first = procOrder($supplierId, [['description' => 'Viti', 'quantity_ordered' => '10', 'unit_price' => '1.50', 'uom' => 'PCE']]);
    $legacy = $this->postJson('/api/v1/purchases/orders', ['supplier_id' => $supplierId, 'date' => now()->toDateString(), 'currency' => 'EUR', 'items' => [['description' => 'Dadi', 'quantity' => 2, 'unit_price' => 3, 'tax_rate' => 22]]])->assertCreated()->json('data.order_number');
    $second = procOrder($supplierId, [['description' => 'Rondelle', 'quantity_ordered' => '5', 'unit_price' => '0.20', 'uom' => 'PCE']]);

    expect(PurchaseOrder::find($first)->po_number)->toBe("PO-{$year}-0001")
        ->and($legacy)->toBe("PO-{$year}-0002")
        ->and(PurchaseOrder::find($second)->po_number)->toBe("PO-{$year}-0003")
        ->and(DocumentSequence::query()->where('tenant_id', $this->tenant->id)->where('document_type', 'purchase_order')->value('last_number'))->toBe(3);

    $this->getJson("/api/v1/procurement/purchase-orders/{$first}")->assertOk()
        ->assertJsonPath('net_amount', '15.00')->assertJsonPath('tax_amount', '3.30')->assertJsonPath('total_amount', '18.30')
        ->assertJsonPath('lines.0.quantity_ordered', '10.0000');

    $this->postJson('/api/v1/procurement/purchase-orders', ['supplier_id' => $supplierId, 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->toDateString(), 'po_number' => 'MINE-1', 'lines' => [['quantity_ordered' => '1', 'unit_price' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('po_number');
});

test('submitting an order starts the approval matrix workflow by amount; a small order is approved at once', function (): void {
    $supplierId = procSupplier();
    $this->postJson('/api/v1/procurement/approval-matrices', [
        'target' => 'purchase_order',
        'name' => 'Orders above 1000',
        'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'owner', 'threshold_min' => '1000']],
    ])->assertCreated()->assertJsonPath('data.target', 'purchase_order');

    $small = procOrder($supplierId, [['description' => 'Small', 'quantity_ordered' => '1', 'unit_price' => '100', 'uom' => 'PCE']]);
    $this->postJson("/api/v1/procurement/purchase-orders/{$small}/submit-approval")->assertOk()->assertJsonPath('status', 'approved');

    $large = procOrder($supplierId, [['description' => 'Large', 'quantity_ordered' => '10', 'unit_price' => '500', 'uom' => 'PCE']]);
    expect(WorkflowInstance::query()->where('approvable_id', $large)->exists())->toBeFalse();

    $this->postJson("/api/v1/procurement/purchase-orders/{$large}/submit-approval")->assertOk()->assertJsonPath('status', 'pending_approval')->assertJsonPath('is_locked', true);
    $instance = WorkflowInstance::query()->where('approvable_id', $large)->sole();
    expect(PurchaseOrder::find($large)->workflow_instance_id)->toBe((string) $instance->id);

    $this->putJson("/api/v1/procurement/purchase-orders/{$large}", ['notes' => 'changed'])->assertForbidden();

    $step = WorkflowInstanceStep::query()->where('workflow_instance_id', $instance->id)->where('status', 'pending')->sole();
    procActAs($this->owner, $this->tenant);
    $this->postJson("/api/v1/workflows/steps/{$step->id}/decide", ['decision' => 'approved'])->assertOk();

    expect(PurchaseOrder::find($large))->status->toBe('approved')->is_locked->toBeFalse()->is_budget_encumbered->toBeTrue()->approved_at->not->toBeNull();
    $this->postJson("/api/v1/procurement/purchase-orders/{$large}/send")->assertOk()->assertJsonPath('status', 'sent');
    $this->postJson("/api/v1/procurement/purchase-orders/{$large}/send")->assertUnprocessable();
});

test('a rejected order goes back to draft when edited and an order with goods cannot be cancelled', function (): void {
    $supplierId = procSupplier();
    $order = procOrder($supplierId, [['description' => 'A', 'quantity_ordered' => '1', 'unit_price' => '10', 'uom' => 'PCE']]);
    PurchaseOrder::query()->whereKey($order)->update(['status' => 'pending_approval']);

    procActAs($this->owner, $this->tenant);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/reject")->assertOk()->assertJsonPath('status', 'rejected');

    procActAs($this->buyer, $this->tenant);
    $this->putJson("/api/v1/procurement/purchase-orders/{$order}", ['lines' => [['description' => 'B', 'quantity_ordered' => '2', 'unit_price' => '10', 'uom' => 'PCE']]])->assertForbidden();
    PurchaseOrder::query()->whereKey($order)->update(['status' => 'draft']);
    $this->putJson("/api/v1/procurement/purchase-orders/{$order}", ['lines' => [['description' => 'B', 'quantity_ordered' => '2', 'unit_price' => '10', 'uom' => 'PCE']]])->assertOk()->assertJsonPath('net_amount', '20.00');

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/cancel", ['reason' => 'not needed'])->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/close")->assertForbidden();
});

test('the creator of an order cannot approve it by hand', function (): void {
    $order = procOrder(procSupplier(), [['description' => 'A', 'quantity_ordered' => '1', 'unit_price' => '10', 'uom' => 'PCE']]);
    PurchaseOrder::query()->whereKey($order)->update(['status' => 'pending_approval']);

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/approve")->assertForbidden();

    procActAs($this->owner, $this->tenant);
    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/approve")->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('approved_by', (string) $this->owner->id);
});

test('an approved requisition with suggested suppliers becomes one purchase order per supplier', function (): void {
    $alfa = procSupplier('Alfa');
    $beta = procSupplier('Beta');

    $requisitionId = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Materiale ufficio',
        'required_by_date' => now()->addDays(5)->toDateString(),
        'urgency' => 'medium',
        'lines' => [
            ['description' => 'Carta', 'quantity_requested' => '10', 'uom' => 'PCE', 'estimated_unit_price' => '4.50', 'suggested_supplier_id' => $alfa],
            ['description' => 'Toner', 'quantity_requested' => '2', 'uom' => 'PCE', 'estimated_unit_price' => '80', 'suggested_supplier_id' => $beta],
        ],
    ])->assertCreated()->json('id');

    expect(PurchaseRequisition::find($requisitionId))->requisition_number->toBe('PR-'.now()->year.'-0001')->total_estimated_amount->toBe('205.00');

    $this->postJson("/api/v1/procurement/requisitions/{$requisitionId}/submit-approval")->assertOk();

    expect(PurchaseRequisition::find($requisitionId)->status)->toBe('converted_to_po')
        ->and(PurchaseOrder::query()->where('purchase_requisition_id', $requisitionId)->count())->toBe(2)
        ->and(PurchaseOrder::query()->where('purchase_requisition_id', $requisitionId)->where('supplier_id', $alfa)->value('net_amount'))->toBe('45.00');
});

test('a requisition with a matrix waits for the workflow and the configured approver decides it', function (): void {
    $this->postJson('/api/v1/procurement/approval-matrices', [
        'target' => 'purchase_requisition',
        'name' => 'All requisitions',
        'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'owner']],
    ])->assertCreated();

    $requisitionId = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Sedie', 'required_by_date' => now()->addDays(5)->toDateString(), 'urgency' => 'low',
        'lines' => [['description' => 'Sedia', 'quantity_requested' => '4', 'uom' => 'PCE', 'estimated_unit_price' => '120']],
    ])->assertCreated()->json('id');

    $this->postJson("/api/v1/procurement/requisitions/{$requisitionId}/submit-approval")->assertOk()->assertJsonPath('status', 'pending_approval');

    $instance = WorkflowInstance::query()->where('approvable_id', $requisitionId)->sole();
    $step = WorkflowInstanceStep::query()->where('workflow_instance_id', $instance->id)->where('status', 'pending')->sole();
    procActAs($this->owner, $this->tenant);
    $this->postJson("/api/v1/workflows/steps/{$step->id}/decide", ['decision' => 'rejected'])->assertOk();

    expect(PurchaseRequisition::find($requisitionId)->status)->toBe('rejected');
});

test('an approved requisition is sourced through a request for quotation and awarded to the cheapest quote', function (): void {
    $alfa = procSupplier('Alfa');
    $beta = procSupplier('Beta');
    $gamma = procSupplier('Gamma');

    $requisitionId = $this->postJson('/api/v1/procurement/requisitions', [
        'title' => 'Banchi lavoro', 'required_by_date' => now()->addDays(20)->toDateString(), 'urgency' => 'high',
        'lines' => [['description' => 'Banco', 'quantity_requested' => '3', 'uom' => 'PCE', 'estimated_unit_price' => '300']],
    ])->assertCreated()->json('id');
    $this->postJson("/api/v1/procurement/requisitions/{$requisitionId}/submit-approval")->assertOk()->assertJsonPath('status', 'approved');

    $rfq = $this->postJson('/api/v1/procurement/rfqs', [
        'title' => 'Banchi', 'response_deadline' => now()->addDays(7)->toDateString(),
        'purchase_requisition_id' => $requisitionId, 'supplier_ids' => [$alfa, $beta],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonCount(1, 'data.lines')->json('data');

    expect(PurchaseRequisition::find($requisitionId)->status)->toBe('sourcing');
    $lineId = $rfq['lines'][0]['id'];

    $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $alfa, 'quote_date' => now()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'unit_price' => '280']]])->assertUnprocessable();
    $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/issue")->assertOk()->assertJsonPath('data.status', 'issued');

    $alfaQuote = $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $alfa, 'quote_date' => now()->toDateString(), 'lead_time_days' => 10, 'lines' => [['rfq_line_id' => $lineId, 'unit_price' => '280']]])
        ->assertCreated()->assertJsonPath('data.total_amount', '840.00')->json('data.id');
    $betaQuote = $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $beta, 'quote_date' => now()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'unit_price' => '265.5']]])
        ->assertCreated()->json('data.id');

    $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $gamma, 'quote_date' => now()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'unit_price' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $alfa, 'quote_date' => now()->toDateString(), 'lines' => [['rfq_line_id' => $lineId, 'unit_price' => '1']]])->assertUnprocessable();
    $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/quotes", ['supplier_id' => $beta, 'quote_date' => now()->addDays(30)->toDateString(), 'lines' => []])->assertUnprocessable()->assertJsonValidationErrors('lines');

    $this->getJson("/api/v1/procurement/rfqs/{$rfq['id']}/comparison")->assertOk()
        ->assertJsonPath('data.recommended_quote_id', $betaQuote)
        ->assertJsonPath('data.lines.0.best_unit_price', '265.5000')
        ->assertJsonPath('data.quotes.1.quote_id', $alfaQuote);

    $order = $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/award", ['supplier_quote_id' => $betaQuote])->assertCreated()
        ->assertJsonPath('supplier_id', $beta)->assertJsonPath('net_amount', '796.50')->assertJsonPath('status', 'draft')->json('id');

    $this->getJson("/api/v1/procurement/rfqs/{$rfq['id']}")->assertOk()->assertJsonPath('data.status', 'awarded')->assertJsonPath('data.purchase_order_id', $order);
    expect(PurchaseRequisition::find($requisitionId)->status)->toBe('converted_to_po')
        ->and(PurchaseOrder::find($order)->rfq_id)->not->toBeNull();
    $this->postJson("/api/v1/procurement/rfqs/{$rfq['id']}/cancel")->assertUnprocessable();
});

test('catalogue prices with quantity breaks and validity price the order lines', function (): void {
    $supplierId = procSupplier();
    $catalogId = $this->postJson('/api/v1/procurement/catalogs', ['supplier_id' => $supplierId, 'supplier_part_number' => 'BRG-608', 'internal_sku' => 'BRG', 'name' => 'Cuscinetto'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/procurement/catalogs/{$catalogId}/prices", ['unit_price' => '2.00', 'valid_from' => now()->subMonth()->toDateString()])->assertCreated();
    $this->postJson("/api/v1/procurement/catalogs/{$catalogId}/prices", ['unit_price' => '1.60', 'min_quantity' => '100', 'valid_from' => now()->subMonth()->toDateString(), 'valid_to' => now()->addMonth()->toDateString()])->assertCreated();
    $this->postJson("/api/v1/procurement/catalogs/{$catalogId}/prices", ['unit_price' => '1.50', 'valid_from' => now()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('valid_from');
    $this->postJson("/api/v1/procurement/catalogs/{$catalogId}/prices", ['unit_price' => '-1', 'valid_from' => 'yesterday'])->assertUnprocessable()->assertJsonValidationErrors(['unit_price', 'valid_from']);

    $this->getJson("/api/v1/procurement/catalogs/{$catalogId}/price?quantity=150")->assertOk()->assertJsonPath('data.unit_price', '1.6000');
    $this->getJson("/api/v1/procurement/catalogs/{$catalogId}/price?quantity=10")->assertOk()->assertJsonPath('data.unit_price', '2.0000');
    $this->getJson("/api/v1/procurement/catalogs/{$catalogId}/price?quantity=150&date=".now()->addMonths(2)->toDateString())->assertOk()->assertJsonPath('data.unit_price', '2.0000');
    $this->getJson('/api/v1/procurement/offers?sku=BRG&quantity=100')->assertOk()->assertJsonPath('data.0.unit_price', '1.6000');

    $order = procOrder($supplierId, [['quantity_ordered' => '100', 'supplier_catalog_id' => $catalogId]]);
    $this->getJson("/api/v1/procurement/purchase-orders/{$order}")->assertOk()
        ->assertJsonPath('lines.0.unit_price', '1.6000')->assertJsonPath('lines.0.list_unit_price', '1.6000')->assertJsonPath('net_amount', '160.00');

    $noPrice = $this->postJson('/api/v1/procurement/catalogs', ['supplier_id' => $supplierId, 'supplier_part_number' => 'X-1', 'name' => 'Senza prezzo'])->json('data.id');
    $this->postJson('/api/v1/procurement/purchase-orders', ['supplier_id' => $supplierId, 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->toDateString(), 'lines' => [['quantity_ordered' => '1', 'supplier_catalog_id' => $noPrice]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.unit_price');
});

test('framework agreement call-offs use the agreed price and cannot exceed the committed quantity', function (): void {
    $supplierId = procSupplier();
    $contractId = $this->postJson('/api/v1/procurement/contracts', [
        'supplier_id' => $supplierId, 'contract_number' => 'FA-1', 'title' => 'Quadro bulloneria', 'contract_type' => 'framework',
        'total_committed_amount' => 10000, 'starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->addYear()->toDateString(),
    ])->assertCreated()->json('id');
    SupplierContract::query()->whereKey($contractId)->update(['status' => 'active']);

    $lineId = $this->postJson("/api/v1/procurement/contracts/{$contractId}/lines", ['description' => 'Bullone M8', 'unit_price' => '0.35', 'committed_quantity' => '1000', 'price_valid_from' => now()->subMonth()->toDateString()])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/procurement/contracts/{$contractId}/lines", ['description' => 'Fuori', 'unit_price' => '1', 'price_valid_from' => now()->subYears(2)->toDateString()])->assertUnprocessable();

    $order = $this->postJson("/api/v1/procurement/contracts/{$contractId}/call-offs", ['expected_delivery_date' => now()->addDays(3)->toDateString(), 'lines' => [['supplier_contract_line_id' => $lineId, 'quantity_ordered' => '600']]])
        ->assertCreated()->assertJsonPath('order_type', 'blanket_release')->assertJsonPath('lines.0.unit_price', '0.3500')->assertJsonPath('net_amount', '210.00')->json('id');

    $this->getJson("/api/v1/procurement/contracts/{$contractId}/lines")->assertOk()->assertJsonPath('data.0.remaining_quantity', '400.0000');
    $this->postJson("/api/v1/procurement/contracts/{$contractId}/call-offs", ['expected_delivery_date' => now()->addDays(3)->toDateString(), 'lines' => [['supplier_contract_line_id' => $lineId, 'quantity_ordered' => '500']]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.supplier_contract_line_id');

    $this->postJson("/api/v1/procurement/purchase-orders/{$order}/cancel")->assertOk();
    $this->getJson("/api/v1/procurement/contracts/{$contractId}/lines")->assertOk()->assertJsonPath('data.0.remaining_quantity', '1000.0000');
    expect(SupplierContract::find($contractId)->consumed_amount)->toBe('0.00');
});

test('supplier qualification and expired mandatory documents block new orders', function (): void {
    $supplierId = procSupplier();
    $line = [['description' => 'A', 'quantity_ordered' => '1', 'unit_price' => '10', 'uom' => 'PCE']];

    $this->putJson("/api/v1/procurement/suppliers/{$supplierId}/qualification", ['qualification_status' => 'disqualified'])->assertOk()->assertJsonPath('data.qualification_status', 'disqualified');
    $this->postJson('/api/v1/procurement/purchase-orders', ['supplier_id' => $supplierId, 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->toDateString(), 'lines' => $line])
        ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

    $documentId = $this->postJson("/api/v1/procurement/suppliers/{$supplierId}/documents", ['document_type' => 'insurance', 'expires_at' => now()->subDay()->toDateString(), 'is_mandatory' => true])
        ->assertCreated()->assertJsonPath('data.is_expired', true)->json('data.id');
    $this->putJson("/api/v1/procurement/suppliers/{$supplierId}/qualification", ['qualification_status' => 'qualified'])->assertUnprocessable()->assertJsonValidationErrors('qualification_status');
    $this->getJson('/api/v1/procurement/supplier-documents/expiring?days=10')->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/procurement/supplier-documents/{$documentId}")->assertNoContent();
    $this->postJson("/api/v1/procurement/suppliers/{$supplierId}/documents", ['document_type' => 'insurance', 'expires_at' => now()->addYear()->toDateString(), 'is_mandatory' => true])->assertCreated();
    $this->putJson("/api/v1/procurement/suppliers/{$supplierId}/qualification", ['qualification_status' => 'qualified', 'review_date' => now()->addYear()->toDateString()])->assertOk();
    procOrder($supplierId, $line);

    $this->postJson("/api/v1/procurement/suppliers/{$supplierId}/documents", ['document_type' => 'iso_9001', 'expires_at' => now()->subDays(3)->toDateString(), 'is_mandatory' => true])->assertCreated();
    $this->postJson('/api/v1/procurement/supplier-qualifications/expire')->assertOk()->assertJsonPath('data.expired', 1);
    $this->getJson("/api/v1/procurement/suppliers/{$supplierId}/documents")->assertOk()->assertJsonCount(2, 'data');
});

test('approval matrices and catalogue entries validate their references', function (): void {
    $foreignSupplier = (function (): string {
        procActAs(procMember($this->otherTenant, 'admin'), $this->otherTenant);

        return procSupplier('Estero');
    })();
    procActAs($this->buyer, $this->tenant);

    $this->postJson('/api/v1/procurement/approval-matrices', ['target' => 'invoice', 'name' => 'X', 'tiers' => [['tier_level' => 1, 'approver_type' => 'user', 'approver_value' => 'nobody']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['target', 'tiers.0.approver_value']);
    $this->postJson('/api/v1/procurement/catalogs', ['supplier_id' => $foreignSupplier, 'supplier_part_number' => 'P', 'name' => 'N'])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    $this->postJson('/api/v1/procurement/rfqs', ['title' => 'T', 'response_deadline' => now()->addDay()->toDateString(), 'supplier_ids' => [$foreignSupplier], 'lines' => [['description' => 'X', 'quantity' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('supplier_ids.0');
    $this->putJson('/api/v1/procurement/settings', ['over_receipt_tolerance_percent' => '150'])->assertUnprocessable()->assertJsonValidationErrors('over_receipt_tolerance_percent');
    $this->putJson('/api/v1/procurement/settings', ['over_receipt_tolerance_percent' => '5', 'price_tolerance_amount' => '2.50'])->assertOk()->assertJsonPath('data.over_receipt_tolerance_percent', '5.00');
    $this->getJson('/api/v1/procurement/settings')->assertOk()->assertJsonPath('data.price_tolerance_amount', '2.50');

    $matrix = $this->postJson('/api/v1/procurement/approval-matrices', ['target' => 'purchase_order', 'name' => 'M', 'tiers' => [['tier_level' => 1, 'approver_type' => 'role', 'approver_value' => 'owner']]])->json('data.id');
    $this->getJson('/api/v1/procurement/approval-matrices')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/procurement/approval-matrices/{$matrix}")->assertNoContent();
    $this->getJson('/api/v1/procurement/approval-matrices')->assertOk()->assertJsonCount(0, 'data');
});
