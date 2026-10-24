<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\EiInboundLog;
use App\Models\PassiveInvoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'fiscal_code' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Acme Supplies SRL', 'country' => 'IT', 'vat_number' => '01234567890']);
});

function buyApi()
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

function fatturaPaXml(array $overrides = []): string
{
    $v = array_merge([
        'recipient_vat' => '00811720580',
        'supplier_vat' => '01234567890',
        'supplier_country' => 'IT',
        'supplier_name' => 'Acme Supplies SRL',
        'type' => 'TD01',
        'number' => 'SI-100',
        'date' => '2026-03-01',
        'total' => '122.00',
        'net' => '100.00',
        'tax' => '22.00',
        'due' => '2026-03-31',
    ], $overrides);

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica versione="FPR12" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2 schema.xsd">
  <FatturaElettronicaHeader>
    <CedentePrestatore><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>{$v['supplier_country']}</IdPaese><IdCodice>{$v['supplier_vat']}</IdCodice></IdFiscaleIVA>
      <Anagrafica><Denominazione>{$v['supplier_name']}</Denominazione></Anagrafica>
    </DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici>
      <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$v['recipient_vat']}</IdCodice></IdFiscaleIVA>
    </DatiAnagrafici></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali><DatiGeneraliDocumento>
      <TipoDocumento>{$v['type']}</TipoDocumento><Divisa>EUR</Divisa><Data>{$v['date']}</Data><Numero>{$v['number']}</Numero>
      <ImportoTotaleDocumento>{$v['total']}</ImportoTotaleDocumento>
    </DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>{$v['net']}</ImponibileImporto><Imposta>{$v['tax']}</Imposta></DatiRiepilogo>
    </DatiBeniServizi>
    <DatiPagamento><DettaglioPagamento><DataScadenzaPagamento>{$v['due']}</DataScadenzaPagamento><ImportoPagamento>{$v['total']}</ImportoPagamento></DettaglioPagamento></DatiPagamento>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
}

function xmlUpload(string $xml, string $name = 'IT01234567890_00001.xml'): array
{
    return ['file' => UploadedFile::fake()->createWithContent($name, $xml)];
}

// ------------------------------------------------------------ Passive invoices

test('an incoming FatturaPA is parsed and stored once for a known supplier', function (): void {
    $response = buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml()))->assertCreated();

    expect($response->json('data.0.duplicate'))->toBeFalse()
        ->and($response->json('data.0.invoice.invoice_number'))->toBe('SI-100')
        ->and((float) $response->json('data.0.invoice.total_amount'))->toEqual(122.0)
        ->and((float) $response->json('data.0.invoice.total_tax'))->toEqual(22.0)
        ->and($response->json('data.0.invoice.supplier_id'))->toBe($this->supplier->id)
        ->and($response->json('data.0.invoice.sdi_file_name'))->toBe('IT01234567890_00001.xml')
        ->and($response->json('data.0.invoice'))->not->toHaveKey('xml_payload');

    $again = buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml()))->assertCreated();
    expect($again->json('data.0.duplicate'))->toBeTrue()->and(PassiveInvoice::count())->toBe(1);

    expect(buyApi()->getJson('/api/v1/purchases/supplier-invoices')->json('meta.total'))->toBe(1);
});

test('unknown suppliers are created and customers sharing the VAT number become both', function (): void {
    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml(['supplier_vat' => '09876543210', 'supplier_name' => 'New Vendor SPA'])))->assertCreated();

    $created = Contact::where('vat_number', '09876543210')->first();
    expect($created->type)->toBe('supplier')->and($created->company_name)->toBe('New Vendor SPA');

    $customer = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'client', 'company_name' => 'Both Ltd', 'country' => 'IT', 'vat_number' => '11111111111']);
    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml(['supplier_vat' => '11111111111', 'number' => 'SI-200'])))->assertCreated();
    expect($customer->refresh()->type)->toBe('both')->and(Contact::where('vat_number', '11111111111')->count())->toBe(1);
});

test('files addressed to another company or not FatturaPA are rejected', function (): void {
    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml(['recipient_vat' => '99999999999'])))
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload('<foo>not an invoice</foo>'))->assertUnprocessable();
    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', [])->assertUnprocessable()->assertJsonValidationErrors('file');

    expect(PassiveInvoice::count())->toBe(0);
});

test('supplier credit notes are stored as TD04 and cannot be paid, invoices can be paid once', function (): void {
    $invoice = buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml()))->json('data.0.invoice.id');
    $credit = buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml(['type' => 'TD04', 'number' => 'NC-1', 'total' => '24.40', 'net' => '20.00', 'tax' => '4.40'])))->json('data.0.invoice.id');

    buyApi()->postJson("/api/v1/purchases/supplier-invoices/{$credit}/mark-paid")->assertUnprocessable();
    buyApi()->postJson("/api/v1/purchases/supplier-invoices/{$invoice}/mark-paid")->assertOk()->assertJsonPath('data.payment_status', 'paid');
    buyApi()->postJson("/api/v1/purchases/supplier-invoices/{$invoice}/mark-paid")->assertUnprocessable();

    expect(buyApi()->getJson('/api/v1/purchases/supplier-invoices?document_type=TD04')->json('meta.total'))->toBe(1)
        ->and(buyApi()->getJson('/api/v1/purchases/supplier-invoices?payment_status=unpaid')->json('meta.total'))->toBe(1);
});

// ------------------------------------------------------------ SDI webhook

test('the incoming invoice webhook requires the secret, routes by recipient VAT and is idempotent', function (): void {
    config(['services.sdi.webhook_secret' => 'topsecret']);
    $payload = ['provider' => 'local', 'file_name' => 'IT01234567890_00002.xml', 'xml' => fatturaPaXml(['number' => 'SI-300'])];

    $this->postJson('/api/v1/webhooks/sdi/incoming-invoice', $payload)->assertUnauthorized();
    $this->withHeaders(['X-Webhook-Secret' => 'wrong'])->postJson('/api/v1/webhooks/sdi/incoming-invoice', $payload)->assertUnauthorized();

    $first = $this->withHeaders(['X-Webhook-Secret' => 'topsecret'])->postJson('/api/v1/webhooks/sdi/incoming-invoice', $payload)->assertCreated();
    expect($first->json('data.documents'))->toBe(1)
        ->and(PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('invoice_number', 'SI-300')->exists())->toBeTrue();

    $second = $this->withHeaders(['X-Webhook-Secret' => 'topsecret'])->postJson('/api/v1/webhooks/sdi/incoming-invoice', $payload)->assertOk();
    expect($second->json('data.duplicate'))->toBeTrue()
        ->and(EiInboundLog::where('event_type', 'INVOICE_RECEIVED')->count())->toBe(1);

    $this->withHeaders(['X-Webhook-Secret' => 'topsecret'])->postJson('/api/v1/webhooks/sdi/incoming-invoice', ['xml' => fatturaPaXml(['recipient_vat' => '99999999999'])])
        ->assertUnprocessable();
});

// ------------------------------------------------------------ Purchase orders & goods receipts

test('purchase orders require a supplier and compute totals', function (): void {
    $client = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'client', 'company_name' => 'Pure Customer', 'country' => 'IT']);
    $payload = ['supplier_id' => $this->supplier->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'items' => [['description' => 'Pump', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22]]];

    buyApi()->postJson('/api/v1/purchases/orders', array_merge($payload, ['supplier_id' => $client->id]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

    $order = buyApi()->postJson('/api/v1/purchases/orders', $payload)->assertCreated();
    expect($order->json('data.order_number'))->toBe('PO-2026-0001')
        ->and($order->json('data.status'))->toBe('draft')
        ->and((float) $order->json('data.total_gross'))->toEqual(122.0);

    $updated = buyApi()->putJson('/api/v1/purchases/orders/'.$order->json('data.id'), ['items' => [['description' => 'Pump', 'quantity' => 4, 'unit_price' => 50, 'tax_rate' => 22]]])->assertOk();
    expect((float) $updated->json('data.total_gross'))->toEqual(244.0);
});

test('goods are received partially then fully, loading tracked stock and locking the order', function (): void {
    Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PMP-1', 'name' => 'Pump', 'cost_price' => 0, 'sale_price' => 90, 'tax_rate' => 22, 'track_inventory' => true]);

    $order = buyApi()->postJson('/api/v1/purchases/orders', [
        'supplier_id' => $this->supplier->id, 'date' => '2026-03-10', 'currency' => 'EUR',
        'items' => [['product_id' => $product->id, 'description' => 'Pump', 'quantity' => 10, 'unit_price' => 40, 'tax_rate' => 22]],
    ])->assertCreated();
    $orderId = $order->json('data.id');
    $lineId = $order->json('data.items.0.id');

    buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/receive")->assertUnprocessable(); // still a draft
    buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/confirm")->assertOk()->assertJsonPath('data.status', 'sent');
    buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/confirm")->assertUnprocessable();

    $first = buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/receive", ['lines' => [$lineId => 4]])->assertCreated();
    expect($first->json('data.receipt_number'))->toBe('GR-2026-0001')
        ->and(buyApi()->getJson("/api/v1/purchases/orders/{$orderId}")->json('data.status'))->toBe('partially_received')
        ->and((float) collect(buyApi()->getJson('/api/v1/inventory/stock-levels')->json('data'))->firstWhere('product_id', $product->id)['quantity'])->toEqual(4.0);

    buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/receive", ['lines' => [$lineId => 7]])->assertUnprocessable(); // only 6 open
    buyApi()->putJson("/api/v1/purchases/orders/{$orderId}", ['notes' => 'late'])->assertUnprocessable();

    $rest = buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/receive")->assertCreated();
    expect((float) $rest->json('data.items.0.quantity'))->toEqual(6.0)
        ->and(buyApi()->getJson("/api/v1/purchases/orders/{$orderId}")->json('data.status'))->toBe('received')
        ->and((float) collect(buyApi()->getJson('/api/v1/inventory/stock-levels')->json('data'))->firstWhere('product_id', $product->id)['quantity'])->toEqual(10.0);

    buyApi()->postJson("/api/v1/purchases/orders/{$orderId}/receive")->assertUnprocessable();

    expect(buyApi()->getJson('/api/v1/purchases/goods-receipts')->json('meta.total'))->toBe(2);
    buyApi()->getJson('/api/v1/purchases/goods-receipts/'.$first->json('data.id'))->assertOk()->assertJsonCount(1, 'data.items');
});

test('suppliers from other countries keep their ISO country code', function (): void {
    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml([
        'supplier_country' => 'DE', 'supplier_vat' => '123456789', 'supplier_name' => 'Berlin Parts GmbH', 'number' => 'DE-1',
    ])))->assertCreated();

    $supplier = Contact::where('vat_number', '123456789')->first();
    expect($supplier->country)->toBe('DE')->and($supplier->type)->toBe('supplier');

    buyApi()->postJson('/api/v1/purchases/passive-invoices/receive-xml', xmlUpload(fatturaPaXml([
        'supplier_country' => 'DE', 'supplier_vat' => '123456789', 'supplier_name' => 'Berlin Parts GmbH', 'number' => 'DE-2',
    ])))->assertCreated();
    expect(Contact::where('vat_number', '123456789')->count())->toBe(1);
});
