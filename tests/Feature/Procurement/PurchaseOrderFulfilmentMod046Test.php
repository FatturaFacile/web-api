<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;

require_once __DIR__.'/ProcurementEnterpriseFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = procTenant('Ordini Fornitore Alfa');
    $this->otherTenant = procTenant('Ordini Fornitore Beta');
    $this->buyer = procMember($this->tenant, 'admin');
    procActAs($this->buyer, $this->tenant);
    $this->supplierId = procSupplier();
    $this->stock = procStockedCatalog($this->tenant, $this->supplierId, 'CARTON-1');
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * An approved order of 10 cartons (25 kg each) at 100.00 with a 20 + 10 + 5 cascade.
 *
 * @param  array<string, mixed>  $line
 */
function po46ApprovedOrder(array $line = []): string
{
    $order = procOrder(test()->supplierId, [[
        'quantity_ordered' => '10',
        'unit_price' => '100',
        'tax_rate' => '22',
        'supplier_catalog_id' => test()->stock['catalog_id'],
        'uom' => 'CTN',
        'base_uom' => 'KG',
        'uom_conversion_factor' => '25',
        'discount_cascade' => [20, 10, 5],
        ...$line,
    ]]);

    test()->postJson("/api/v1/procurement/purchase-orders/{$order}/submit-approval")->assertOk()->assertJsonPath('status', 'approved');

    return $order;
}

/**
 * A dropship sales order of 4 pieces for a customer, with its single line.
 *
 * @return array{order: SalesOrder, item: SalesOrderItem}
 */
function po46DropshipSalesOrder(string $type = 'dropship'): array
{
    $customer = Contact::create(['tenant_id' => test()->tenant->id, 'type' => 'client', 'country' => 'CH', 'company_name' => 'Cliente Finale SA']);
    $order = SalesOrder::create(['tenant_id' => test()->tenant->id, 'contact_id' => $customer->id, 'order_number' => 'SO-DS-'.random_int(100, 999), 'date' => now()->toDateString(), 'currency' => 'EUR', 'total_net' => 400, 'total_tax' => 0, 'total_gross' => 400, 'status' => 'confirmed', 'order_type' => $type]);
    $item = SalesOrderItem::create(['tenant_id' => test()->tenant->id, 'sales_order_id' => $order->id, 'description' => 'Articolo in triangolazione', 'quantity' => 4, 'unit_price' => 100, 'tax_rate' => 0, 'line_total' => 400]);

    return ['order' => $order, 'item' => $item];
}

/**
 * @return array<string, mixed>
 */
function po46Address(): array
{
    return ['street' => 'Via Nassa', 'house_number' => '12', 'postal_code' => '6900', 'city' => 'Lugano', 'region' => 'TI', 'country_code' => 'ch', 'phone' => '+41 91 000 00 00', 'delivery_notes' => 'Consegna al piano terra'];
}

// ---------------------------------------------------------------------------------------------------------------
// Cascading discounts, dual unit of measure and currency
// ---------------------------------------------------------------------------------------------------------------

test('a line applies the cascading discount to the list price and converts the order unit to the base unit', function (): void {
    $id = po46ApprovedOrder();

    $line = test()->getJson("/api/v1/procurement/purchase-orders/{$id}")->assertOk()->json('lines.0');

    expect($line['list_unit_price'])->toBe('100.0000')
        ->and($line['unit_price'])->toBe('68.4000')
        ->and($line['line_net_amount'])->toBe('684.00')
        ->and($line['line_tax_amount'])->toBe('150.48')
        ->and($line['discount_cascade'])->toBe(['20.0000', '10.0000', '5.0000'])
        ->and($line['discount_percent_effective'])->toBe('31.6000')
        ->and($line['uom'])->toBe('CTN')
        ->and($line['base_uom'])->toBe('KG')
        ->and($line['quantity_base_ordered'])->toBe('250.0000');
});

test('the supplier default cascade applies to lines that give none', function (): void {
    DB::table('suppliers')->where('id', $this->supplierId)->update(['metadata' => json_encode(['default_discount_cascade' => [10, 10]])]);

    $order = procOrder($this->supplierId, [['quantity_ordered' => '5', 'unit_price' => '200', 'tax_rate' => '0', 'supplier_catalog_id' => $this->stock['catalog_id']]]);

    test()->getJson("/api/v1/procurement/purchase-orders/{$order}")->assertOk()
        ->assertJsonPath('lines.0.line_net_amount', '810.00')
        ->assertJsonPath('lines.0.discount_percent_effective', '19.0000');
});

test('the pricing preview returns the net, base unit and company currency amounts', function (): void {
    test()->postJson('/api/v1/procurement/purchase-orders/pricing-preview', [
        'quantity' => '10', 'unit_price' => '100', 'discount_cascade' => [20, 10, 5], 'uom_conversion_factor' => '25', 'exchange_rate' => '0.945', 'tax_rate' => '22',
    ])->assertOk()
        ->assertJsonPath('data.effective_discount_percent', '31.6000')
        ->assertJsonPath('data.net_unit_price', '68.4000')
        ->assertJsonPath('data.line_net_amount', '684.00')
        ->assertJsonPath('data.line_total_amount', '834.48')
        ->assertJsonPath('data.quantity_base', '250.0000')
        ->assertJsonPath('data.net_price_per_base_unit', '2.736000')
        ->assertJsonPath('data.line_net_amount_company_currency', '646.38');
});

test('a discount step above one hundred percent is refused', function (): void {
    test()->postJson('/api/v1/procurement/purchase-orders', [
        'supplier_id' => $this->supplierId, 'order_date' => now()->toDateString(), 'expected_delivery_date' => now()->addDays(5)->toDateString(),
        'lines' => [['quantity_ordered' => '1', 'unit_price' => '10', 'discount_cascade' => [120]]],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.discount_cascade.0');
});

test('the exchange rate keeps its date and source on the order', function (): void {
    $order = procOrder($this->supplierId, [['quantity_ordered' => '1', 'unit_price' => '10', 'tax_rate' => '0', 'supplier_catalog_id' => $this->stock['catalog_id']]], [
        'currency' => 'CHF', 'exchange_rate' => '1.050000', 'exchange_rate_date' => '2027-12-01', 'exchange_rate_source' => 'snb',
    ]);

    test()->getJson("/api/v1/procurement/purchase-orders/{$order}")->assertOk()
        ->assertJsonPath('currency', 'CHF')
        ->assertJsonPath('exchange_rate', '1.050000')
        ->assertJsonPath('exchange_rate_date', '2027-12-01')
        ->assertJsonPath('exchange_rate_source', 'snb');
});

// ---------------------------------------------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------------------------------------------

test('the first dispatch moves an approved order to sent and logs the channel', function (): void {
    $id = po46ApprovedOrder();

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/dispatch", ['channel' => 'email', 'recipient' => 'ordini@fornitore.example'])
        ->assertCreated()
        ->assertJsonPath('channel', 'email')
        ->assertJsonPath('recipient', 'ordini@fornitore.example');

    test()->getJson("/api/v1/procurement/purchase-orders/{$id}")->assertJsonPath('status', 'sent')->assertJsonPath('dispatch_channel', 'email');
    test()->getJson("/api/v1/procurement/purchase-orders/{$id}/dispatches")->assertOk()->assertJsonCount(1, 'data');

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/dispatch", ['channel' => 'pdf'])->assertCreated();
    test()->getJson("/api/v1/procurement/purchase-orders/{$id}/dispatches")->assertJsonCount(2, 'data');
});

test('an electronic channel needs a recipient and a draft order cannot be dispatched', function (): void {
    $approved = po46ApprovedOrder();
    $draft = procOrder($this->supplierId, [['quantity_ordered' => '1', 'unit_price' => '10', 'supplier_catalog_id' => $this->stock['catalog_id']]]);

    test()->postJson("/api/v1/procurement/purchase-orders/{$approved}/dispatch", ['channel' => 'peppol'])->assertUnprocessable()->assertJsonValidationErrors('recipient');
    test()->postJson("/api/v1/procurement/purchase-orders/{$draft}/dispatch", ['channel' => 'pdf'])->assertForbidden();
    test()->postJson("/api/v1/procurement/purchase-orders/{$approved}/dispatch", ['channel' => 'carrier-pigeon'])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Supplier confirmation and variances
// ---------------------------------------------------------------------------------------------------------------

test('a supplier confirmation records variances, moves the order to confirmed and sets the promised date for MRP', function (): void {
    $id = po46ApprovedOrder();
    $line = PurchaseOrderLine::query()->where('purchase_order_id', $id)->firstOrFail();
    $promised = now()->addDays(20)->toDateString();

    $response = test()->postJson("/api/v1/procurement/purchase-orders/{$id}/confirmations", [
        'confirmation_number' => 'AB-2027-0042',
        'lines' => [['purchase_order_line_id' => $line->id, 'confirmed_quantity' => '8', 'confirmed_unit_price' => '70', 'confirmed_delivery_date' => $promised, 'variance_reason' => 'Raw material surcharge']],
    ])->assertCreated()
        ->assertJsonPath('confirmation_number', 'AB-2027-0042')
        ->assertJsonPath('has_variance', true)
        ->assertJsonPath('requires_review', true)
        ->assertJsonPath('lines.0.quantity_variance', '-2.0000')
        ->assertJsonPath('lines.0.quantity_variance_base', '-50.0000')
        ->assertJsonPath('lines.0.price_variance', '1.6000')
        ->assertJsonPath('lines.0.delivery_delay_days', 10);

    expect($response->json('lines.0.price_variance_percent'))->toBe('2.3392')
        ->and($response->json('lines.0.line_total_variance'))->toBe('12.80');

    $order = test()->getJson("/api/v1/procurement/purchase-orders/{$id}")->assertOk();
    $order->assertJsonPath('status', 'confirmed')
        ->assertJsonPath('supplier_confirmation_number', 'AB-2027-0042')
        ->assertJsonPath('promised_delivery_date', $promised)
        ->assertJsonPath('lines.0.quantity_confirmed', '8.0000');

    // MRP reads the delivery date of the header: it now is the date the supplier promised.
    expect(PurchaseOrder::query()->findOrFail($id)->getRawOriginal('delivery_due_date'))->toStartWith($promised);
});

test('a confirmation that matches the order has no variance', function (): void {
    $id = po46ApprovedOrder();
    $date = now()->addDays(10)->toDateString();

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/confirmations", ['confirmation_number' => 'C-1', 'confirmed_delivery_date' => $date])
        ->assertCreated()
        ->assertJsonPath('has_variance', false)
        ->assertJsonPath('requires_review', false)
        ->assertJsonPath('price_variance_total', '0.00');
});

test('a confirmation can be repeated and goods can still be received afterwards', function (): void {
    $id = po46ApprovedOrder();

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/confirmations", ['confirmation_number' => 'C-1'])->assertCreated();
    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/confirmations", ['confirmation_number' => 'C-2', 'confirmed_delivery_date' => now()->addDays(30)->toDateString()])->assertCreated();

    test()->getJson("/api/v1/procurement/purchase-orders/{$id}/confirmations")->assertOk()->assertJsonCount(2, 'data');
    test()->getJson("/api/v1/procurement/purchase-orders/{$id}")->assertJsonPath('status', 'confirmed')->assertJsonPath('supplier_confirmation_number', 'C-2');
});

test('a draft order cannot be confirmed and a foreign line is refused', function (): void {
    $draft = procOrder($this->supplierId, [['quantity_ordered' => '1', 'unit_price' => '10', 'supplier_catalog_id' => $this->stock['catalog_id']]]);
    $approved = po46ApprovedOrder();

    test()->postJson("/api/v1/procurement/purchase-orders/{$draft}/confirmations", ['confirmation_number' => 'C-1'])->assertForbidden();
    test()->postJson("/api/v1/procurement/purchase-orders/{$approved}/confirmations", [
        'confirmation_number' => 'C-1', 'lines' => [['purchase_order_line_id' => (string) Str::uuid(), 'confirmed_quantity' => '1']],
    ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.purchase_order_line_id');
});

// ---------------------------------------------------------------------------------------------------------------
// Overdue orders and reminders
// ---------------------------------------------------------------------------------------------------------------

test('an order past its promised date is overdue, a reminder is logged and the next one waits for the interval', function (): void {
    $id = po46ApprovedOrder();
    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/dispatch", ['channel' => 'email', 'recipient' => 'ordini@fornitore.example'])->assertCreated();

    test()->getJson('/api/v1/procurement/purchase-orders/overdue')->assertOk()->assertJsonCount(0, 'data');

    DB::table('purchase_order_lines')->where('purchase_order_id', $id)->update(['expected_delivery_date' => now()->subDays(5)->toDateString()]);
    DB::table('purchase_orders')->where('id', $id)->update(['expected_delivery_date' => now()->subDays(5)->toDateString()]);

    $overdue = test()->getJson('/api/v1/procurement/purchase-orders/overdue')->assertOk()->assertJsonCount(1, 'data');
    expect($overdue->json('data.0.days_overdue'))->toBe(5)
        ->and($overdue->json('data.0.next_reminder_due'))->toBeTrue()
        ->and($overdue->json('data.0.reminder_count'))->toBe(0);

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/reminders")->assertCreated()->assertJsonPath('level', 1)->assertJsonPath('trigger', 'manual')->assertJsonPath('channel', 'email');

    test()->getJson('/api/v1/procurement/purchase-orders/overdue')->assertJsonPath('data.0.next_reminder_due', false)->assertJsonPath('data.0.reminder_count', 1);
    test()->postJson('/api/v1/procurement/purchase-orders/reminders/run')->assertOk()->assertJsonPath('sent', 0);
    test()->getJson("/api/v1/procurement/purchase-orders/{$id}/reminders")->assertOk()->assertJsonCount(1, 'data');
});

test('the automatic run reminds again after the interval and stops at the maximum level', function (): void {
    $id = po46ApprovedOrder();
    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/dispatch", ['channel' => 'pdf'])->assertCreated();
    DB::table('purchase_order_lines')->where('purchase_order_id', $id)->update(['expected_delivery_date' => now()->subDays(20)->toDateString()]);

    foreach ([1, 2, 3] as $level) {
        test()->postJson('/api/v1/procurement/purchase-orders/reminders/run')->assertOk()->assertJsonPath('sent', 1);
        DB::table('purchase_orders')->where('id', $id)->update(['last_reminder_at' => now()->subDays(8)]);
    }

    test()->postJson('/api/v1/procurement/purchase-orders/reminders/run')->assertOk()->assertJsonPath('sent', 0);
    expect(PurchaseOrder::query()->findOrFail($id)->reminder_count)->toBe(3);
});

test('a promised date in the future keeps the order off the overdue list', function (): void {
    $id = po46ApprovedOrder();
    $line = PurchaseOrderLine::query()->where('purchase_order_id', $id)->firstOrFail();
    DB::table('purchase_order_lines')->where('purchase_order_id', $id)->update(['expected_delivery_date' => now()->subDays(3)->toDateString()]);

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/confirmations", [
        'confirmation_number' => 'C-9', 'lines' => [['purchase_order_line_id' => $line->id, 'confirmed_delivery_date' => now()->addDays(4)->toDateString()]],
    ])->assertCreated();

    test()->getJson('/api/v1/procurement/purchase-orders/overdue')->assertOk()->assertJsonCount(0, 'data');
    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/reminders")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------
// Dropshipment
// ---------------------------------------------------------------------------------------------------------------

test('a dropship sales order generates the linked supplier order with the final customer address', function (): void {
    ['order' => $sales, 'item' => $item] = po46DropshipSalesOrder();

    $response = test()->postJson('/api/v1/procurement/dropshipments', [
        'sales_order_id' => $sales->id,
        'supplier_id' => $this->supplierId,
        'delivery_address' => po46Address(),
        'lines' => [['sales_order_item_id' => $item->id, 'supplier_catalog_id' => $this->stock['catalog_id'], 'unit_price' => '60', 'uom' => 'PCE']],
    ])->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('recipient_name', 'Cliente Finale SA')
        ->assertJsonPath('recipient_city', 'Lugano')
        ->assertJsonPath('recipient_country_code', 'CH');

    $order = PurchaseOrder::query()->findOrFail($response->json('purchase_order_id'));
    expect($order->is_dropship)->toBeTrue()
        ->and($order->sales_order_id)->toBe($sales->id)
        ->and($order->order_type)->toBe('dropship')
        ->and($order->source_type)->toBe('sales_order')
        ->and($order->shipping_address['city'])->toBe('Lugano')
        ->and($order->lines()->firstOrFail()->sales_order_line_id)->toBe($item->id);

    // A retry returns the same order instead of ordering twice.
    test()->postJson('/api/v1/procurement/dropshipments', ['sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => po46Address(), 'lines' => [['sales_order_item_id' => $item->id, 'supplier_catalog_id' => $this->stock['catalog_id'], 'unit_price' => '60']]])
        ->assertCreated()->assertJsonPath('purchase_order_id', $order->id);
    expect(PurchaseOrder::query()->where('sales_order_id', $sales->id)->count())->toBe(1);
});

test('only a dropship sales order can be fulfilled by the supplier', function (): void {
    ['order' => $sales, 'item' => $item] = po46DropshipSalesOrder('standard');

    test()->postJson('/api/v1/procurement/dropshipments', [
        'sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => po46Address(),
        'lines' => [['sales_order_item_id' => $item->id, 'unit_price' => '60']],
    ])->assertUnprocessable()->assertJsonValidationErrors('sales_order_id');

    test()->postJson('/api/v1/procurement/dropshipments', ['sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => ['street' => 'Via Nassa']])
        ->assertUnprocessable()->assertJsonValidationErrors(['delivery_address.postal_code', 'delivery_address.city', 'delivery_address.country_code']);
});

test('the custom DDT carries our header, the supplier origin and the customer destination', function (): void {
    ['order' => $sales, 'item' => $item] = po46DropshipSalesOrder();
    $dropship = test()->postJson('/api/v1/procurement/dropshipments', [
        'sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => po46Address(),
        'lines' => [['sales_order_item_id' => $item->id, 'supplier_catalog_id' => $this->stock['catalog_id'], 'unit_price' => '60']],
    ])->assertCreated()->json();

    // The supplier does not hold the order yet.
    test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/ddt")->assertForbidden();

    test()->postJson("/api/v1/procurement/purchase-orders/{$dropship['purchase_order_id']}/submit-approval")->assertOk();
    $ddt = test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/ddt", ['carrier' => 'Posta Svizzera', 'packages' => 2])->assertOk()->assertJsonPath('status', 'ddt_issued');

    expect($ddt->json('ddt_number'))->toStartWith('DDT-DS-')
        ->and($ddt->json('ddt_payload.sender.name'))->toBe('Ordini Fornitore Alfa SpA')
        ->and($ddt->json('ddt_payload.destination.name'))->toBe('Cliente Finale SA')
        ->and($ddt->json('ddt_payload.destination.street'))->toBe('Via Nassa 12')
        ->and($ddt->json('ddt_payload.origin.supplier_id'))->toBe($this->supplierId)
        ->and($ddt->json('ddt_payload.transport.carrier'))->toBe('Posta Svizzera');

    // Numbered once.
    test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/ddt")->assertOk()->assertJsonPath('ddt_number', $ddt->json('ddt_number'));
});

test('the delivery notice books the virtual warehouse and authorises the invoicing of the customer', function (): void {
    ['order' => $sales, 'item' => $item] = po46DropshipSalesOrder();
    $dropship = test()->postJson('/api/v1/procurement/dropshipments', [
        'sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => po46Address(),
        'lines' => [['sales_order_item_id' => $item->id, 'supplier_catalog_id' => $this->stock['catalog_id'], 'unit_price' => '60', 'uom' => 'PCE', 'base_uom' => 'PCE']],
    ])->assertCreated()->json();
    $orderId = $dropship['purchase_order_id'];
    $lineId = (string) PurchaseOrderLine::query()->where('purchase_order_id', $orderId)->firstOrFail()->id;
    test()->postJson("/api/v1/procurement/purchase-orders/{$orderId}/submit-approval")->assertOk();

    // Partial delivery: three of four pieces.
    test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/deliveries", [
        'delivery_proof_reference' => 'CMR-778', 'lines' => [['purchase_order_line_id' => $lineId, 'quantity_delivered' => '3']],
    ])->assertOk()
        ->assertJsonPath('status', 'partially_delivered')
        ->assertJsonPath('billing_authorized', true)
        ->assertJsonCount(2, 'virtual_stock_movements');

    test()->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertJsonPath('status', 'partially_received')->assertJsonPath('lines.0.quantity_received', '3.0000');
    $reference = test()->getJson("/api/v1/procurement/dropshipments/{$dropship['id']}")->json('billing_authorization_reference');
    expect($reference)->toStartWith('DSB-');

    // The rest, then nothing is left.
    test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/deliveries")->assertOk()->assertJsonPath('status', 'delivered')->assertJsonCount(4, 'virtual_stock_movements');
    test()->getJson("/api/v1/procurement/purchase-orders/{$orderId}")->assertJsonPath('status', 'received')->assertJsonPath('lines.0.quantity_base_received', '4.0000');
    test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/deliveries")->assertForbidden();

    $movements = DB::table('purchase_order_virtual_stock_movements')->where('purchase_order_dropship_id', $dropship['id'])->get();
    expect((float) $movements->where('direction', 'in')->sum('quantity_base'))->toBe(4.0)
        ->and((float) $movements->where('direction', 'out')->sum('valued_amount'))->toBe(240.0);

    $salesRow = DB::table('sales_orders')->where('id', $sales->id)->first();
    expect($salesRow->billing_authorized_at)->not->toBeNull()
        ->and($salesRow->billing_authorization_reference)->toBe($reference);
});

test('a delivery beyond the open quantity is refused and nothing is booked', function (): void {
    ['order' => $sales, 'item' => $item] = po46DropshipSalesOrder();
    $dropship = test()->postJson('/api/v1/procurement/dropshipments', [
        'sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => po46Address(),
        'lines' => [['sales_order_item_id' => $item->id, 'supplier_catalog_id' => $this->stock['catalog_id'], 'unit_price' => '60']],
    ])->assertCreated()->json();
    $lineId = (string) PurchaseOrderLine::query()->where('purchase_order_id', $dropship['purchase_order_id'])->firstOrFail()->id;
    test()->postJson("/api/v1/procurement/purchase-orders/{$dropship['purchase_order_id']}/submit-approval")->assertOk();

    test()->postJson("/api/v1/procurement/dropshipments/{$dropship['id']}/deliveries", ['lines' => [['purchase_order_line_id' => $lineId, 'quantity_delivered' => '5']]])
        ->assertUnprocessable();

    expect(DB::table('purchase_order_virtual_stock_movements')->count())->toBe(0)
        ->and(DB::table('purchase_order_dropships')->where('id', $dropship['id'])->value('billing_authorized'))->toBeFalsy();
});

// ---------------------------------------------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------------------------------------------

test('another company cannot see or act on the orders, confirmations and dropshipments of this one', function (): void {
    $id = po46ApprovedOrder();
    ['order' => $sales, 'item' => $item] = po46DropshipSalesOrder();
    $dropship = test()->postJson('/api/v1/procurement/dropshipments', [
        'sales_order_id' => $sales->id, 'supplier_id' => $this->supplierId, 'delivery_address' => po46Address(),
        'lines' => [['sales_order_item_id' => $item->id, 'supplier_catalog_id' => $this->stock['catalog_id'], 'unit_price' => '60']],
    ])->assertCreated()->json();

    procActAs(procMember($this->otherTenant, 'admin'), $this->otherTenant);

    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/confirmations", ['confirmation_number' => 'X'])->assertNotFound();
    test()->postJson("/api/v1/procurement/purchase-orders/{$id}/dispatch", ['channel' => 'pdf'])->assertNotFound();
    test()->getJson("/api/v1/procurement/dropshipments/{$dropship['id']}")->assertNotFound();
    test()->getJson('/api/v1/procurement/dropshipments')->assertOk()->assertJsonCount(0, 'data');
    test()->getJson('/api/v1/procurement/purchase-orders/overdue')->assertOk()->assertJsonCount(0, 'data');
});
