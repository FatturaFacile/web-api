<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Catalog\PriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\SalesRepresentative;
use Modules\Invoicing\Services\PricingAndDiscountService;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierPriceList;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    logisticsUser($this->tenant);
    $this->product = Product::create(['sku' => 'P-'.Str::random(6), 'name' => 'Vite', 'sale_price' => 50]);
    $this->base = PriceList::create(['name' => 'Base', 'currency' => 'EUR', 'is_active' => true]);
    $this->base->items()->create(['product_id' => $this->product->id, 'price' => 40, 'min_quantity' => 1]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function childList(array $attributes = []): PriceList
{
    return PriceList::create($attributes + ['name' => 'Figlio '.Str::random(4), 'currency' => 'EUR', 'is_active' => true, 'parent_price_list_id' => test()->base->id]);
}

test('a child list inherits from its parents with each adjustment', function (): void {
    $middle = childList(['price_adjustment_percent' => '-10']);
    $child = childList(['parent_price_list_id' => $middle->id, 'price_adjustment_percent' => '5']);
    $resolver = app(PriceResolver::class);

    expect($resolver->priceInList($middle, $this->product->id, 1, now()))->toBe('36.0000')
        ->and($resolver->priceInList($child, $this->product->id, 1, now()))->toBe('37.8000');
});

test('a price of the child wins over the parent', function (): void {
    $child = childList(['price_adjustment_percent' => '-50']);
    $child->items()->create(['product_id' => $this->product->id, 'price' => 39, 'min_quantity' => 1]);

    expect(app(PriceResolver::class)->priceInList($child, $this->product->id, 1, now()))->toBe('39.0000');
});

test('item validity selects the price of the day and an expired parent gives nothing', function (): void {
    $child = childList();
    $child->items()->create(['product_id' => $this->product->id, 'price' => 30, 'min_quantity' => 1, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
    $child->items()->create(['product_id' => $this->product->id, 'price' => 35, 'min_quantity' => 1, 'valid_from' => '2026-07-01']);
    $resolver = app(PriceResolver::class);

    expect($resolver->priceInList($child, $this->product->id, 1, now()->setDate(2026, 3, 1)))->toBe('30.0000')
        ->and($resolver->priceInList($child, $this->product->id, 1, now()->setDate(2026, 8, 1)))->toBe('35.0000')
        ->and($resolver->priceInList($child, $this->product->id, 1, now()->setDate(2025, 8, 1)))->toBe('40.0000');

    $this->base->update(['valid_to' => '2025-01-01']);

    expect($resolver->priceInList($child, $this->product->id, 1, now()->setDate(2025, 8, 1)))->toBeNull();
});

test('a contact takes its price through the inherited list', function (): void {
    $child = childList(['price_adjustment_percent' => '-10']);
    $contact = Contact::create(['company_name' => 'Cliente', 'price_list_id' => $child->id]);

    $this->getJson('/api/v1/catalog/price-lists/resolve-price?'.http_build_query(['product_id' => $this->product->id, 'contact_id' => $contact->id, 'quantity' => 1]))
        ->assertOk()
        ->assertJsonPath('data.price', 36);
});

test('the api takes parents and refuses a loop or a foreign parent', function (): void {
    $child = $this->postJson('/api/v1/catalog/price-lists', ['name' => 'Agenti', 'currency' => 'EUR', 'parent_price_list_id' => $this->base->id, 'price_adjustment_percent' => -5])->assertCreated();

    $this->putJson("/api/v1/catalog/price-lists/{$this->base->id}", ['parent_price_list_id' => $child->json('data.id')])->assertStatus(422);
    $this->putJson("/api/v1/catalog/price-lists/{$this->base->id}", ['parent_price_list_id' => $this->base->id])->assertStatus(422);

    $foreign = logisticsTenant('Beta');
    logisticsUser($foreign);
    $this->postJson('/api/v1/catalog/price-lists', ['name' => 'X', 'currency' => 'EUR', 'parent_price_list_id' => $this->base->id])->assertStatus(422);
});

test('an agent can enter an order on a tablet with what the api offers', function (): void {
    $child = childList(['price_adjustment_percent' => '-10']);
    $contact = Contact::create(['company_name' => 'Cliente']);
    $agent = SalesRepresentative::create(['rep_code' => 'A1', 'name' => 'Agente']);

    logisticsUser($this->tenant, 'sales');
    $this->getJson('/api/v1/crm/contacts')->assertOk();

    $order = $this->postJson('/api/v1/invoicing/orders', [
        'contact_id' => $contact->id,
        'price_list_id' => $child->id,
        'sales_rep_id' => $agent->id,
        'items' => [['product_id' => $this->product->id, 'description' => 'Vite', 'quantity' => 2, 'tax_rate' => 22]],
    ])->assertCreated();

    expect((float) DB::table('sales_order_items')->where('sales_order_id', $order->json('data.id') ?? $order->json('id'))->value('unit_price'))->toBe(36.0);

    $this->getJson("/api/v1/invoicing/orders?sales_rep_id={$agent->id}")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/invoicing/orders?sales_rep_id='.Str::uuid7())->assertOk()->assertJsonCount(0, 'data');
});

test('an order refuses an agent or a list of another tenant', function (): void {
    $contact = Contact::create(['company_name' => 'Cliente']);
    $agent = SalesRepresentative::create(['rep_code' => 'A1', 'name' => 'Agente']);
    $tenantB = logisticsTenant('Beta');
    logisticsUser($tenantB);
    $contactB = Contact::create(['company_name' => 'Altro']);

    $this->postJson('/api/v1/invoicing/orders', ['contact_id' => $contactB->id, 'sales_rep_id' => $agent->id, 'items' => [['description' => 'X', 'quantity' => 1, 'tax_rate' => 22]]])->assertStatus(422);
    $this->postJson('/api/v1/invoicing/orders', ['contact_id' => $contactB->id, 'price_list_id' => $this->base->id, 'items' => [['description' => 'X', 'quantity' => 1, 'tax_rate' => 22]]])->assertStatus(422);
    expect($contact->tenant_id)->toBe($this->tenant->id);
});

test('the module pricing leaves an expired list for the sale price of the product', function (): void {
    $pricing = new PricingAndDiscountService;

    expect($pricing->resolvePrice($this->product->id, 1.0, $this->base->id)['unit_price'])->toBe(40.0);

    $this->base->update(['valid_to' => now()->subDay()]);

    expect($pricing->resolvePrice($this->product->id, 1.0, $this->base->id)['unit_price'])->toBe(50.0);
});

/**
 * @return array{order: PurchaseOrder, line: PurchaseOrderLine, catalog: SupplierCatalog}
 */
function purchaseOrderWithLine(string $unitPrice = '10.0000'): array
{
    $supplier = Supplier::factory()->create(['tenant_id' => test()->tenant->id]);
    $catalog = SupplierCatalog::create(['supplier_id' => $supplier->id, 'supplier_part_number' => 'PN-1', 'internal_sku' => 'SKU-1', 'name' => 'Vite']);
    $order = PurchaseOrder::factory()->create(['tenant_id' => test()->tenant->id, 'supplier_id' => $supplier->id]);
    $line = PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'supplier_catalog_id' => $catalog->id, 'description' => 'Vite M6', 'quantity_ordered' => 100, 'unit_price' => $unitPrice, 'tax_rate' => 22, 'line_net_amount' => 1000, 'line_tax_amount' => 220, 'line_total_amount' => 1220]);

    return ['order' => $order, 'line' => $line, 'catalog' => $catalog];
}

test('invoiced prices are checked against order and price list', function (): void {
    ['order' => $order, 'catalog' => $catalog] = purchaseOrderWithLine();
    SupplierPriceList::create(['supplier_catalog_id' => $catalog->id, 'unit_price' => '9.5000', 'min_quantity' => 1, 'valid_from' => '2026-01-01']);

    $response = $this->postJson('/api/v1/procurement/price-checks', [
        'purchase_order_id' => $order->id,
        'date' => '2026-06-01',
        'lines' => [
            ['code' => 'PN-1', 'quantity' => 100, 'unit_price' => 10.5],
            ['description' => 'vite m6', 'quantity' => 100, 'unit_price' => 9],
            ['code' => 'PN-1', 'quantity' => 100, 'unit_price' => 10],
            ['code' => 'NOPE', 'unit_price' => 1],
        ],
    ])->assertOk()->assertJsonPath('has_differences', true);

    expect(array_column($response->json('lines'), 'status'))->toBe(['above_order', 'below_order', 'above_list', 'unmatched'])
        ->and($response->json('lines.0.variance_percent'))->toBe('5.00')
        ->and($response->json('lines.0.list_unit_price'))->toBe('9.5000');
});

test('a tolerance from the caller absorbs small differences', function (): void {
    ['order' => $order] = purchaseOrderWithLine();

    $this->postJson('/api/v1/procurement/price-checks', ['purchase_order_id' => $order->id, 'tolerance_percent' => 5, 'lines' => [['code' => 'PN-1', 'unit_price' => 10.5]]])
        ->assertOk()
        ->assertJsonPath('has_differences', false)
        ->assertJsonPath('lines.0.status', 'ok');
});

test('the lines of an electronic invoice come from its xml', function (): void {
    ['order' => $order] = purchaseOrderWithLine();
    $xml = '<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2"><FatturaElettronicaBody><DatiBeniServizi><DettaglioLinee><NumeroLinea>1</NumeroLinea><CodiceArticolo><CodiceTipo>INT</CodiceTipo><CodiceValore>PN-1</CodiceValore></CodiceArticolo><Descrizione>Vite</Descrizione><Quantita>100.00</Quantita><PrezzoUnitario>12.00</PrezzoUnitario></DettaglioLinee></DatiBeniServizi></FatturaElettronicaBody></p:FatturaElettronica>';
    $supplier = Contact::create(['company_name' => 'Fornitore', 'type' => 'supplier']);
    $invoice = PassiveInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'F1', 'date' => '2026-06-01', 'total_amount' => 1200, 'currency' => 'EUR', 'xml_payload' => $xml]);
    $empty = PassiveInvoice::create(['supplier_id' => $supplier->id, 'invoice_number' => 'F2', 'date' => '2026-06-01', 'total_amount' => 1, 'currency' => 'EUR']);

    $this->postJson('/api/v1/procurement/price-checks', ['purchase_order_id' => $order->id, 'passive_invoice_id' => $invoice->id])
        ->assertOk()
        ->assertJsonPath('lines.0.status', 'above_order')
        ->assertJsonPath('lines.0.invoiced_unit_price', '12.0000');

    $this->postJson('/api/v1/procurement/price-checks', ['purchase_order_id' => $order->id, 'passive_invoice_id' => $empty->id])->assertStatus(422);
});

test('price checks stay within the tenant and need procurement write access', function (): void {
    ['order' => $order] = purchaseOrderWithLine();

    logisticsUser($this->tenant, 'sales');
    $this->postJson('/api/v1/procurement/price-checks', ['purchase_order_id' => $order->id])->assertForbidden();

    logisticsUser(logisticsTenant('Beta'));
    $this->postJson('/api/v1/procurement/price-checks', ['purchase_order_id' => $order->id, 'lines' => [['unit_price' => 1]]])->assertStatus(422);
});
