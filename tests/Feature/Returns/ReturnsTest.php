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
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\RmaLine;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = rtTenant('Reso');
    $this->owner = rtMember($this->tenant, 'owner');
    rtAct($this->owner, $this->tenant);
    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi Srl', 'country' => 'IT']);
    $this->warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $this->product = rtProduct($this->tenant, 'WID-1', 'Widget');
});

afterEach(fn () => app(TenantContext::class)->clear());

function rtTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function rtMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@returns-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function rtAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function rtProduct(Tenant $tenant, string $sku, string $name): Product
{
    return Product::create(['tenant_id' => $tenant->id, 'sku' => $sku, 'name' => $name, 'cost_price' => 40, 'sale_price' => 100, 'tax_rate' => 22, 'track_inventory' => true]);
}

/**
 * An invoice of the test customer with one line of the product.
 *
 * @return array{invoice: Invoice, item: InvoiceItem}
 */
function rtInvoice(string $quantity = '5', string $date = '2027-06-10', ?Product $product = null, string $unitPrice = '100'): array
{
    $invoice = Invoice::create([
        'tenant_id' => test()->tenant->id,
        'contact_id' => test()->contact->id,
        'invoice_number' => 'INV-'.Str::upper(Str::random(6)),
        'date' => $date,
        'total_net' => 500,
        'total_gross' => 610,
    ]);
    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'product_id' => ($product ?? test()->product)->id,
        'description' => 'Widget',
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'tax_rate' => 22,
        'line_total' => 500,
    ]);

    return ['invoice' => $invoice, 'item' => $item];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function rtRma(array $source, string $quantity = '2', array $overrides = []): array
{
    return test()->postJson('/api/v1/returns/rmas', [
        'contact_id' => test()->contact->id,
        'invoice_id' => $source['invoice']->id,
        'reason_code' => 'defective',
        'lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => $quantity, 'serial_number' => 'SN-1']],
        ...$overrides,
    ])->assertCreated()->json('data');
}

function rtStock(Product $product): float
{
    return app(StockService::class)->quantityOf((string) $product->id);
}

/**
 * Moves a return to the inspected status with one disposition for its first line.
 *
 * @param  array<string, mixed>  $inspection
 */
function rtInspected(array $rma, array $inspection = ['disposition' => 'restock']): array
{
    test()->postJson("/api/v1/returns/rmas/{$rma['id']}/approve")->assertOk();
    test()->postJson("/api/v1/returns/rmas/{$rma['id']}/receive")->assertOk();

    return test()->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [['id' => $rma['lines'][0]['id'], ...$inspection]]])->assertOk()->json('data');
}

it('starts with the default window and reason codes and lets the company change both', function (): void {
    $settings = $this->getJson('/api/v1/returns/settings')->assertOk()->json('data');
    expect($settings['return_window_days'])->toBe(30)
        ->and(collect($settings['reason_codes'])->pluck('code')->all())->toContain('defective', 'wrong_item', 'other');

    $this->putJson('/api/v1/returns/settings', ['return_window_days' => 14])->assertOk()->assertJsonPath('data.return_window_days', 14);
    $this->postJson('/api/v1/returns/reason-codes', ['code' => 'recalled', 'label' => 'Product recall', 'is_defect' => true])->assertCreated();
    $this->postJson('/api/v1/returns/reason-codes', ['code' => 'recalled', 'label' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->getJson('/api/v1/returns/reason-codes?active=1')->assertOk()->assertJsonFragment(['code' => 'recalled']);

    $source = rtInvoice();
    rtRma($source, '1', ['reason_code' => 'recalled']);
    $reasonId = collect($this->getJson('/api/v1/returns/reason-codes')->json('data'))->firstWhere('code', 'recalled')['id'];
    $this->deleteJson("/api/v1/returns/reason-codes/{$reasonId}")->assertOk()->assertJsonPath('deleted', false);
    $this->postJson('/api/v1/returns/rmas', [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'recalled',
        'lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('reason_code');

    $unusedId = collect($this->getJson('/api/v1/returns/reason-codes')->json('data'))->firstWhere('code', 'changed_mind')['id'];
    $this->deleteJson("/api/v1/returns/reason-codes/{$unusedId}")->assertOk()->assertJsonPath('deleted', true);
});

it('moves a return through the whole workflow, restocks through the inventory and refunds the inspected value', function (): void {
    $source = rtInvoice('5', '2027-06-10', null, '99.90');
    $rma = rtRma($source, '2');

    expect($rma['status'])->toBe('requested')
        ->and($rma['rma_number'])->toBe('RMA-2027-0001')
        ->and($rma['customer_name'])->toBe('Rossi Srl')
        ->and($rma['lines'][0]['unit_price'])->toBe('99.9000')
        ->and($rma['allowed_transitions'])->toBe(['approved', 'rejected']);

    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [['id' => $rma['lines'][0]['id'], 'disposition' => 'restock']]])->assertUnprocessable();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/receive")->assertUnprocessable();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->putJson("/api/v1/returns/rmas/{$rma['id']}", ['notes' => 'late change'])->assertUnprocessable();

    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/receive", ['lines' => [['id' => $rma['lines'][0]['id'], 'quantity_received' => 3]]])->assertUnprocessable();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/receive", ['lines' => [['id' => $rma['lines'][0]['id'], 'quantity_received' => 1]]])
        ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.lines.0.quantity_received', '1.0000');
    expect(rtStock($this->product))->toBe(0.0);

    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => []])->assertUnprocessable();
    $inspected = $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [['id' => $rma['lines'][0]['id'], 'disposition' => 'restock', 'notes' => 'Box damaged, item fine']]])
        ->assertOk()->json('data');
    expect($inspected['status'])->toBe('inspected')
        ->and($inspected['lines'][0]['disposition'])->toBe('restock')
        ->and($inspected['lines'][0]['quantity_inspected'])->toBe('1.0000')
        ->and($inspected['lines'][0]['stock_movement_id'])->not->toBeNull()
        ->and(rtStock($this->product))->toBe(1.0);

    $resolved = $this->postJson("/api/v1/returns/rmas/{$rma['id']}/resolve", ['resolution_type' => 'refund'])->assertOk()->json('data');
    expect($resolved['status'])->toBe('resolved')->and($resolved['refund_amount'])->toBe('99.90');

    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/reject", ['reason' => 'Too late'])->assertUnprocessable();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/close")->assertUnprocessable();
    $this->getJson('/api/v1/returns/rmas?status=closed&search=RMA-2027')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/returns/rmas?status=requested')->assertOk()->assertJsonCount(0, 'data');
});

it('limits the quantity to what was sold less what other returns already took back', function (): void {
    $source = rtInvoice('5');
    $first = rtRma($source, '3');

    $this->postJson('/api/v1/returns/rmas', [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'defective',
        'lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 3]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
    $this->postJson('/api/v1/returns/rmas', [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'defective',
        'lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 1.5], ['invoice_item_id' => $source['item']->id, 'quantity' => 1.5]],
    ])->assertUnprocessable();

    $second = rtRma($source, '2');
    $this->postJson('/api/v1/returns/rmas', [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'defective',
        'lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 0.5]],
    ])->assertUnprocessable();

    $this->postJson("/api/v1/returns/rmas/{$second['id']}/reject", ['reason' => 'Not covered'])->assertOk();
    rtRma($source, '2');

    $this->putJson("/api/v1/returns/rmas/{$first['id']}", ['lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 4]]])->assertUnprocessable();
    $this->putJson("/api/v1/returns/rmas/{$first['id']}", ['lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 3]], 'notes' => 'Kept'])->assertOk()->assertJsonPath('data.notes', 'Kept');

    $sources = $this->getJson("/api/v1/returns/sources?contact_id={$this->contact->id}")->assertOk()->json('data');
    $line = collect($sources)->firstWhere('id', (string) $source['invoice']->id)['lines'][0];
    expect($line['source_item_id'])->toBe((string) $source['item']->id)
        ->and($line['quantity_sold'])->toBe('5.0000')
        ->and($line['quantity_returned'])->toBe('5.0000')
        ->and($line['quantity_available'])->toBe('0.0000')
        ->and(collect($sources)->firstWhere('id', (string) $source['invoice']->id)['within_window'])->toBeTrue();
    $this->getJson('/api/v1/returns/sources')->assertUnprocessable();

    $other = rtInvoice('1');
    $this->postJson('/api/v1/returns/rmas', [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'defective',
        'lines' => [['invoice_item_id' => $other['item']->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.invoice_item_id');
    $this->postJson('/api/v1/returns/rmas', ['contact_id' => $this->contact->id, 'reason_code' => 'defective', 'lines' => [['quantity' => 1]]])->assertUnprocessable();
});

it('applies the return window of the settings and reserves the override to approvers', function (): void {
    $source = rtInvoice('5', '2027-04-01');
    $payload = [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'defective',
        'lines' => [['invoice_item_id' => $source['item']->id, 'quantity' => 1]],
    ];

    $this->postJson('/api/v1/returns/rmas', $payload)->assertUnprocessable()->assertJsonValidationErrors('requested_on');

    rtAct(rtMember($this->tenant, 'sales'), $this->tenant);
    $this->postJson('/api/v1/returns/rmas', [...$payload, 'override_window' => true])->assertUnprocessable()->assertJsonValidationErrors('override_window');

    rtAct($this->owner, $this->tenant);
    $this->postJson('/api/v1/returns/rmas', [...$payload, 'override_window' => true])->assertCreated()->assertJsonPath('data.window_overridden', true);

    $this->putJson('/api/v1/returns/settings', ['return_window_days' => 120])->assertOk();
    $this->postJson('/api/v1/returns/rmas', $payload)->assertCreated()->assertJsonPath('data.window_overridden', false);
});

it('books the disposition of each line: scrap and refurbish move no stock, return to supplier drafts a supplier return', function (): void {
    $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    $source = rtInvoice('6');
    $rma = $this->postJson('/api/v1/returns/rmas', [
        'contact_id' => $this->contact->id, 'invoice_id' => $source['invoice']->id, 'reason_code' => 'defective',
        'lines' => [
            ['invoice_item_id' => $source['item']->id, 'quantity' => 1],
            ['invoice_item_id' => $source['item']->id, 'quantity' => 2],
            ['invoice_item_id' => $source['item']->id, 'quantity' => 3],
        ],
    ])->assertCreated()->json('data');
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/approve")->assertOk();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/receive")->assertOk();
    [$scrap, $refurbish, $toSupplier] = collect($rma['lines'])->pluck('id')->all();

    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [
        ['id' => $scrap, 'disposition' => 'scrap'],
        ['id' => $refurbish, 'disposition' => 'refurbish'],
    ]])->assertUnprocessable();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [
        ['id' => $scrap, 'disposition' => 'scrap'],
        ['id' => $refurbish, 'disposition' => 'refurbish'],
        ['id' => $toSupplier, 'disposition' => 'return_to_supplier'],
    ]])->assertUnprocessable();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [
        ['id' => $scrap, 'disposition' => 'discard'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.disposition');

    $inspected = $this->postJson("/api/v1/returns/rmas/{$rma['id']}/inspect", ['lines' => [
        ['id' => $scrap, 'disposition' => 'scrap'],
        ['id' => $refurbish, 'disposition' => 'refurbish', 'quantity_inspected' => 1.5],
        ['id' => $toSupplier, 'disposition' => 'return_to_supplier', 'supplier_id' => $supplier->id],
    ]])->assertOk()->json('data');

    $lines = collect($inspected['lines'])->keyBy('id');
    expect($lines[$scrap]['stock_movement_id'])->toBeNull()
        ->and($lines[$refurbish]['stock_movement_id'])->toBeNull()
        ->and($lines[$refurbish]['quantity_inspected'])->toBe('1.5000')
        ->and($lines[$toSupplier]['stock_movement_id'])->not->toBeNull()
        ->and(rtStock($this->product))->toBe(3.0);

    $return = $this->getJson('/api/v1/returns/supplier-returns?status=draft')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
    expect($return['supplier_id'])->toBe((string) $supplier->id)
        ->and($return['rma_id'])->toBe($rma['id'])
        ->and($return['lines'][0]['quantity'])->toBe('3.0000')
        ->and($return['credit_expected'])->toBe('120.00');

    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/ship", ['carrier' => 'DHL', 'tracking_number' => 'TRK-123'])
        ->assertOk()->assertJsonPath('data.status', 'shipped')->assertJsonPath('data.tracking_number', 'TRK-123');
    expect(rtStock($this->product))->toBe(0.0);
});

it('resolves with a credit note reference, a replacement from stock or a repair', function (): void {
    $source = rtInvoice('9');

    $creditNote = rtInspected(rtRma($source, '2'));
    $this->postJson("/api/v1/returns/rmas/{$creditNote['id']}/resolve", ['resolution_type' => 'credit_note'])->assertUnprocessable()->assertJsonValidationErrors('credit_note_reference');
    $this->postJson("/api/v1/returns/rmas/{$creditNote['id']}/resolve", ['resolution_type' => 'credit_note', 'credit_note_reference' => 'CN-DRAFT-1', 'refund_amount' => 150])
        ->assertOk()->assertJsonPath('data.refund_amount', '150.00')->assertJsonPath('data.credit_note_reference', 'CN-DRAFT-1');
    $this->postJson("/api/v1/returns/rmas/{$creditNote['id']}/resolve", ['resolution_type' => 'repair'])->assertUnprocessable();
    expect(rtStock($this->product))->toBe(2.0);

    $replacement = rtInspected(rtRma($source, '1'));
    expect(rtStock($this->product))->toBe(3.0);
    $this->postJson("/api/v1/returns/rmas/{$replacement['id']}/resolve", ['resolution_type' => 'replacement'])
        ->assertOk()->assertJsonPath('data.resolution_type', 'replacement')->assertJsonPath('data.refund_amount', null);
    expect(rtStock($this->product))->toBe(2.0);

    $repair = rtInspected(rtRma($source, '1'), ['disposition' => 'refurbish']);
    $this->postJson("/api/v1/returns/rmas/{$repair['id']}/resolve", ['resolution_type' => 'repair', 'notes' => 'Sent to the workshop'])
        ->assertOk()->assertJsonPath('data.resolution_type', 'repair');
    expect(rtStock($this->product))->toBe(2.0);
});

it('keeps the warranty registry with a validity check and the expiring filter', function (): void {
    $payload = [
        'product_id' => $this->product->id, 'contact_id' => $this->contact->id, 'serial_number' => 'SN-100',
        'starts_on' => '2027-01-01', 'ends_on' => '2028-01-01', 'coverage_type' => 'parts', 'coverage_terms' => 'Parts only',
    ];
    $warranty = $this->postJson('/api/v1/returns/warranties', $payload)->assertCreated()->json('data');
    expect($warranty['validity']['valid'])->toBeTrue()
        ->and($warranty['product_name'])->toBe('Widget')
        ->and($warranty['customer_name'])->toBe('Rossi Srl');

    $this->postJson('/api/v1/returns/warranties', $payload)->assertUnprocessable()->assertJsonValidationErrors('serial_number');
    $this->postJson('/api/v1/returns/warranties', [...$payload, 'serial_number' => null])->assertUnprocessable()->assertJsonValidationErrors('serial_number');
    $this->postJson('/api/v1/returns/warranties', [...$payload, 'serial_number' => 'SN-101', 'ends_on' => '2026-12-31'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    $lot = $this->postJson('/api/v1/returns/warranties', [...$payload, 'serial_number' => null, 'lot_number' => 'LOT-9', 'ends_on' => '2027-07-01'])->assertCreated()->json('data');
    $this->postJson('/api/v1/returns/warranties', [...$payload, 'serial_number' => 'SN-OLD', 'starts_on' => '2025-01-01', 'ends_on' => '2026-01-01'])->assertCreated();

    $this->getJson('/api/v1/returns/warranties?expiring=30')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $lot['id']);
    $this->getJson('/api/v1/returns/warranties?expiring=365')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/returns/warranties?expired=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.serial_number', 'SN-OLD');
    $this->getJson('/api/v1/returns/warranties?search=LOT-9')->assertOk()->assertJsonCount(1, 'data');

    $this->getJson("/api/v1/returns/warranties/{$warranty['id']}/validity?on=2028-02-01")->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', 'expired');
    $this->getJson("/api/v1/returns/warranties/{$warranty['id']}/validity?on=2026-12-31")->assertOk()->assertJsonPath('data.reason', 'not_started');
    $this->getJson("/api/v1/returns/warranties/{$warranty['id']}/validity")->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.days_left', 200);

    $this->putJson("/api/v1/returns/warranties/{$warranty['id']}", ['ends_on' => '2029-01-01', 'serial_number' => 'ignored'])->assertOk()->assertJsonPath('data.ends_on', '2029-01-01')->assertJsonPath('data.serial_number', 'SN-100');
    $this->postJson("/api/v1/returns/warranties/{$warranty['id']}/void", ['reason' => 'Registered by mistake'])->assertOk()->assertJsonPath('data.validity.reason', 'void');
    $this->putJson("/api/v1/returns/warranties/{$warranty['id']}", ['ends_on' => '2030-01-01'])->assertUnprocessable();
    $this->postJson('/api/v1/returns/warranties', $payload)->assertCreated();
});

it('freezes the validity on a warranty claim and lets only an approver accept an uncovered one', function (): void {
    $covered = $this->postJson('/api/v1/returns/warranties', [
        'product_id' => $this->product->id, 'contact_id' => $this->contact->id, 'serial_number' => 'SN-A', 'starts_on' => '2027-01-01', 'ends_on' => '2028-01-01',
    ])->assertCreated()->json('data');
    $expired = $this->postJson('/api/v1/returns/warranties', [
        'product_id' => $this->product->id, 'contact_id' => $this->contact->id, 'serial_number' => 'SN-B', 'starts_on' => '2025-01-01', 'ends_on' => '2026-01-01',
    ])->assertCreated()->json('data');
    $source = rtInvoice('2');
    $rma = rtRma($source, '1');

    $valid = $this->postJson('/api/v1/returns/claims', ['warranty_id' => $covered['id'], 'description' => 'Does not switch on', 'rma_id' => $rma['id']])->assertCreated()->json('data');
    expect($valid['status'])->toBe('open')->and($valid['is_valid'])->toBeTrue()->and($valid['claim_number'])->toBe('CLM-2027-0001')->and($valid['rma_number'])->toBe($rma['rma_number']);

    $late = $this->postJson('/api/v1/returns/claims', ['warranty_id' => $expired['id'], 'description' => 'Broken screen'])->assertCreated()->json('data');
    expect($late['is_valid'])->toBeFalse()->and($late['validity_note'])->toBe('expired');

    $otherContact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Verdi Srl', 'country' => 'IT']);
    $foreign = Rma::factory()->create(['tenant_id' => $this->tenant->id, 'contact_id' => $otherContact->id]);
    $this->postJson('/api/v1/returns/claims', ['warranty_id' => $covered['id'], 'description' => 'Wrong return', 'rma_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('rma_id');

    $this->postJson("/api/v1/returns/claims/{$late['id']}/accept")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/returns/claims/{$valid['id']}/resolve")->assertUnprocessable();

    rtAct(rtMember($this->tenant, 'sales'), $this->tenant);
    $this->postJson("/api/v1/returns/claims/{$valid['id']}/accept")->assertForbidden();

    rtAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/returns/claims/{$late['id']}/accept", ['override_validity' => true, 'notes' => 'Goodwill'])->assertOk()->assertJsonPath('data.status', 'accepted');
    $this->postJson("/api/v1/returns/claims/{$valid['id']}/accept")->assertOk();
    $this->postJson("/api/v1/returns/claims/{$valid['id']}/reject", ['reason' => 'Too late'])->assertUnprocessable();
    $this->postJson("/api/v1/returns/claims/{$late['id']}/resolve")->assertOk()->assertJsonPath('data.status', 'resolved');

    $rejected = $this->postJson('/api/v1/returns/claims', ['warranty_id' => $covered['id'], 'description' => 'Misuse'])->assertCreated()->json('data');
    $this->postJson("/api/v1/returns/claims/{$rejected['id']}/reject", ['reason' => 'Water damage'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->getJson('/api/v1/returns/claims?status=accepted')->assertOk()->assertJsonCount(1, 'data');

    rtInspected($rma);
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/resolve", ['resolution_type' => 'repair'])->assertOk();
    $this->getJson("/api/v1/returns/claims/{$valid['id']}")->assertOk()->assertJsonPath('data.status', 'resolved');
});

it('runs a supplier return from draft to credit with tracking and keeps its state machine', function (): void {
    $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    $payload = [
        'supplier_id' => $supplier->id, 'reason_code' => 'defective', 'carrier' => 'UPS', 'currency' => 'EUR',
        'lines' => [['product_id' => $this->product->id, 'quantity' => 4, 'unit_cost' => 12.5, 'serial_number' => 'SN-9']],
    ];
    $this->postJson('/api/v1/returns/supplier-returns', [...$payload, 'supplier_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    $this->postJson('/api/v1/returns/supplier-returns', [...$payload, 'lines' => []])->assertUnprocessable();

    $return = $this->postJson('/api/v1/returns/supplier-returns', $payload)->assertCreated()->json('data');
    expect($return['return_number'])->toBe('RTV-2027-0001')
        ->and($return['status'])->toBe('draft')
        ->and($return['credit_expected'])->toBe('50.00')
        ->and($return['supplier_name'])->toBe($supplier->company_name);

    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/credit", ['credit_received' => 50])->assertUnprocessable();
    $this->putJson("/api/v1/returns/supplier-returns/{$return['id']}", ['lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 12.5]]])->assertOk()->assertJsonPath('data.credit_expected', '25.00');

    app(StockService::class)->record((string) $this->product->id, 'in', 10.0, null, $this->warehouse, 40.0);
    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/ship", ['tracking_number' => '1Z999'])->assertOk()->assertJsonPath('data.status', 'shipped');
    expect(rtStock($this->product))->toBe(8.0);

    $this->putJson("/api/v1/returns/supplier-returns/{$return['id']}", ['tracking_number' => '1Z000', 'notes' => 'Re-labelled'])->assertOk()->assertJsonPath('data.tracking_number', '1Z000');
    $this->putJson("/api/v1/returns/supplier-returns/{$return['id']}", ['supplier_id' => $supplier->id])->assertUnprocessable();
    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/cancel")->assertUnprocessable();
    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/ship")->assertUnprocessable();
    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/credit", ['credit_received' => 24.5])->assertOk()->assertJsonPath('data.credit_received', '24.50');
    $this->postJson("/api/v1/returns/supplier-returns/{$return['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson("/api/v1/returns/supplier-returns/{$return['id']}", ['notes' => 'Closed'])->assertUnprocessable();

    $cancelled = $this->postJson('/api/v1/returns/supplier-returns', $payload)->assertCreated()->json('data');
    $this->postJson("/api/v1/returns/supplier-returns/{$cancelled['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->getJson('/api/v1/returns/supplier-returns?search=1Z000')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/returns/supplier-returns?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
});

it('summarises return rate by product and reason, the ageing of open returns and the refunded value', function (): void {
    $source = rtInvoice('10', '2027-06-01', null, '100');
    $closed = rtInspected(rtRma($source, '2', ['reason_code' => 'defective']));
    $this->postJson("/api/v1/returns/rmas/{$closed['id']}/resolve", ['resolution_type' => 'refund'])->assertOk();
    rtRma($source, '1', ['reason_code' => 'wrong_item']);
    rtRma($source, '1', ['reason_code' => 'wrong_item', 'requested_on' => '2027-04-20']);

    $summary = $this->getJson('/api/v1/returns/summary?from=2027-01-01&to=2027-12-31')->assertOk()->json('data');

    expect($summary['totals']['rma_count'])->toBe(3)
        ->and($summary['totals']['open_rma_count'])->toBe(2)
        ->and($summary['totals']['refund_value'])->toBe('200.00')
        ->and($summary['by_reason'][0])->toBe(['reason_code' => 'defective', 'rma_count' => 1, 'quantity' => '2.0000'])
        ->and(collect($summary['by_reason'])->firstWhere('reason_code', 'wrong_item'))->toBe(['reason_code' => 'wrong_item', 'rma_count' => 2, 'quantity' => '2.0000'])
        ->and($summary['by_product'][0]['returned_quantity'])->toBe('4.0000')
        ->and($summary['by_product'][0]['sold_quantity'])->toBe('10.0000')
        ->and($summary['by_product'][0]['return_rate'])->toBe('40.00')
        ->and(collect($summary['ageing'])->pluck('count', 'bucket')->all())->toBe(['0_7' => 1, '8_30' => 0, '31_60' => 1, 'over_60' => 0])
        ->and($summary['by_status']['requested'])->toBe(2)
        ->and($summary['by_status']['resolved'])->toBe(1);

    $this->getJson('/api/v1/returns/summary?from=2027-12-31&to=2027-01-01')->assertUnprocessable();
});

it('isolates companies, keeps approvals for approvers and refuses writes to read-only roles', function (): void {
    $source = rtInvoice('3');
    $rma = rtRma($source, '1');

    $outsider = rtTenant('Altra');
    rtAct(rtMember($outsider, 'owner'), $outsider);
    $this->getJson("/api/v1/returns/rmas/{$rma['id']}")->assertNotFound();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/approve")->assertNotFound();
    $this->getJson('/api/v1/returns/rmas')->assertOk()->assertJsonCount(0, 'data');
    expect($this->getJson('/api/v1/returns/settings')->json('data.reason_codes'))->toHaveCount(count(ReturnReasonCode::Defaults));

    rtAct(rtMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson("/api/v1/returns/rmas/{$rma['id']}")->assertOk();
    $this->postJson('/api/v1/returns/rmas', ['contact_id' => $this->contact->id])->assertForbidden();

    rtAct(rtMember($this->tenant, 'sales'), $this->tenant);
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/approve")->assertForbidden();
    $this->postJson("/api/v1/returns/rmas/{$rma['id']}/reject", ['reason' => 'No'])->assertForbidden();
    $this->getJson("/api/v1/returns/rmas/{$rma['id']}")->assertOk()->assertJsonPath('data.status', 'requested');

    $this->getJson('/api/v1/returns/rmas')->assertOk();
    expect(RmaLine::query()->count())->toBe(1);
});
