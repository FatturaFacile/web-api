<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesOrder;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, user: User, contact: Contact, product: Product}
 */
function ftWorld(string $tenantCountry, string $contactCountry, ?float $netWeight = 2.5): array
{
    $tenant = Tenant::create(['name' => 'FT '.Str::random(4), 'legal_name' => 'FT SA', 'country' => $tenantCountry, 'default_currency' => $tenantCountry === 'CH' ? 'CHF' : 'EUR']);
    $user = User::create(['name' => 'Trader', 'email' => Str::random(10).'@example.test', 'password' => bcrypt(Str::password(24)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    fiscalCalendar($tenant, (int) now()->year);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'contact' => Contact::create(['tenant_id' => $tenant->id, 'type' => 'client', 'company_name' => 'Buyer', 'country' => $contactCountry]),
        'product' => Product::create(['tenant_id' => $tenant->id, 'sku' => 'FT-'.Str::random(5), 'name' => 'Pump', 'sale_price' => 100, 'tax_rate' => 0, 'taric_code' => '84137035', 'country_of_origin' => $tenantCountry, 'net_weight_kg' => $netWeight]),
    ];
}

/**
 * @param  array{tenant: Tenant, user: User, contact: Contact, product: Product}  $world
 */
function ftApi(array $world): mixed
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($world['user']);

    return test()->withHeader('X-Tenant-Id', (string) $world['tenant']->id);
}

/**
 * @param  array{tenant: Tenant, user: User, contact: Contact, product: Product}  $world
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ftInvoicePayload(array $world, array $overrides = []): array
{
    return [
        'contact_id' => $world['contact']->id,
        'invoice_type' => 'standard',
        'jurisdiction' => $world['tenant']->country === 'CH' ? 'CH' : 'IT',
        'date' => now()->toDateString(),
        'currency' => $world['tenant']->default_currency,
        'items' => [['product_id' => $world['product']->id, 'description' => 'Pump', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ...$overrides,
    ];
}

it('walks the cross-border corridors: block, complete, issue and resolve the regime', function (string $tenantCountry, string $contactCountry, string $regime, bool $vies, bool $intrastat, bool $customs): void {
    $world = ftWorld($tenantCountry, $contactCountry);
    $api = ftApi($world);

    $api->postJson('/api/v1/sales/invoices', ftInvoicePayload($world))->assertUnprocessable()->assertJsonValidationErrors(['incoterm']);

    $id = $api->postJson('/api/v1/sales/invoices', ftInvoicePayload($world, ['incoterm' => 'DAP', 'incoterm_place' => 'Milano']))
        ->assertCreated()->assertJsonPath('data.incoterm', 'DAP')->assertJsonPath('data.incoterm_place', 'Milano')->json('data.id');

    $api->getJson("/api/v1/sales/invoices/{$id}/cross-border-readiness")
        ->assertOk()
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.regime.regime', $regime)
        ->assertJsonPath('data.regime.requires_vies', $vies)
        ->assertJsonPath('data.regime.requires_intrastat', $intrastat)
        ->assertJsonPath('data.regime.requires_customs_declaration', $customs);
})->with([
    'CH to IT export' => ['CH', 'IT', 'ch_export_art_23_ltva', false, false, true],
    'IT to CH export' => ['IT', 'CH', 'it_export_art_8_dpr_633_72', false, false, true],
    'IT to DE intra-EU supply' => ['IT', 'DE', 'it_intra_eu_art_41_dl_331_93', true, true, false],
    'IT to US extra-EU export' => ['IT', 'US', 'it_export_art_8_dpr_633_72', false, false, true],
]);

it('does not touch domestic invoices', function (): void {
    $world = ftWorld('IT', 'IT', null);

    ftApi($world)->postJson('/api/v1/sales/invoices', ftInvoicePayload($world))->assertCreated();
});

it('blocks a cross-border invoice whose goods have no net weight, and reports which line', function (): void {
    $world = ftWorld('IT', 'CH', null);

    ftApi($world)->postJson('/api/v1/sales/invoices', ftInvoicePayload($world, ['incoterm' => 'FCA']))
        ->assertUnprocessable()->assertJsonValidationErrors(['items.0.net_weight_missing']);
});

it('rejects an invalid Incoterm and lets a proforma through without customs data', function (): void {
    $world = ftWorld('IT', 'CH', null);
    $api = ftApi($world);

    $api->postJson('/api/v1/sales/invoices', ftInvoicePayload($world, ['incoterm' => 'XYZ']))->assertUnprocessable()->assertJsonValidationErrors(['incoterm']);
    $api->postJson('/api/v1/sales/invoices', ftInvoicePayload($world, ['invoice_type' => 'proforma']))->assertCreated();
});

it('takes the default Incoterm of the customer when the invoice states none', function (): void {
    $world = ftWorld('IT', 'CH');
    $world['contact']->update(['default_incoterm' => 'EXW']);

    ftApi($world)->postJson('/api/v1/sales/invoices', ftInvoicePayload($world))->assertCreated()->assertJsonPath('data.incoterm', 'EXW');
});

it('validates the EORI number and default Incoterm of a customer', function (): void {
    $world = ftWorld('IT', 'CH');
    $api = ftApi($world);

    $api->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Swiss AG', 'eori_number' => 'CHE123456789', 'default_incoterm' => 'DAP'])
        ->assertUnprocessable()->assertJsonValidationErrors(['eori_number']);
    $api->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Swiss AG', 'eori_number' => 'CHE123456788', 'default_incoterm' => 'ZZZ'])
        ->assertUnprocessable()->assertJsonValidationErrors(['default_incoterm']);
    $api->postJson('/api/v1/crm/contacts', ['type' => 'client', 'country' => 'CH', 'company_name' => 'Swiss AG', 'eori_number' => 'CHE123456788', 'default_incoterm' => 'DAP'])
        ->assertCreated()->assertJsonPath('data.eori_number', 'CHE123456788')->assertJsonPath('data.default_incoterm', 'DAP');
});

it('stores the Incoterm on a sales order', function (): void {
    $world = ftWorld('IT', 'CH');

    $order = ftApi($world)->postJson('/api/v1/invoicing/orders', [
        'contact_id' => $world['contact']->id,
        'auto_submit' => false,
        'incoterm' => 'CIP',
        'incoterm_place' => 'Zurich',
        'items' => [['product_id' => $world['product']->id, 'description' => 'Pump', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
    ])->assertCreated()->assertJsonPath('incoterm', 'CIP')->assertJsonPath('incoterm_place', 'Zurich');

    expect(SalesOrder::withoutGlobalScopes()->whereKey($order->json('id'))->value('incoterm'))->toBe('CIP');
    ftApi($world)->postJson('/api/v1/invoicing/orders', [
        'contact_id' => $world['contact']->id,
        'incoterm' => 'NOPE',
        'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['incoterm']);
});

it('keeps the readiness check inside the tenant of the caller', function (): void {
    $mine = ftWorld('IT', 'CH');
    $theirs = ftWorld('IT', 'CH');
    $foreign = Invoice::create(['tenant_id' => $theirs['tenant']->id, 'contact_id' => $theirs['contact']->id, 'invoice_number' => 'F-'.Str::random(5), 'date' => now()->toDateString(), 'currency' => 'EUR', 'incoterm' => 'DAP']);
    InvoiceItem::create(['invoice_id' => $foreign->id, 'description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);

    ftApi($mine)->getJson("/api/v1/sales/invoices/{$foreign->id}/cross-border-readiness")->assertNotFound();
});

it('computes the VAT regime and the customs value through the API for every Incoterm group', function (): void {
    $world = ftWorld('CH', 'IT');
    $api = ftApi($world);

    $api->postJson('/api/v1/foreign-trade/vat-regime', ['tenant_country' => 'IT', 'counterparty_country' => 'FR', 'is_purchase' => true])
        ->assertOk()->assertJsonPath('data.reverse_charge', true)->assertJsonPath('data.requires_intrastat', true);
    $api->postJson('/api/v1/foreign-trade/vat-regime', ['tenant_country' => 'CH', 'counterparty_country' => 'DE', 'is_purchase' => true])
        ->assertOk()->assertJsonPath('data.regime', 'ch_import_tax_zaz');
    $api->postJson('/api/v1/foreign-trade/customs-value', ['incoterm' => 'CPT', 'goods_value' => 1000, 'freight' => 100, 'insurance' => 10])
        ->assertOk()->assertJsonPath('data.customs_value', 1010)->assertJsonPath('data.freight_added', 0)->assertJsonPath('data.insurance_added', 10);
    $api->postJson('/api/v1/foreign-trade/customs-value', ['goods_value' => 1])->assertUnprocessable()->assertJsonValidationErrors(['incoterm']);
});

it('stores the Incoterm on an order of the sales API and carries it to the invoice', function (): void {
    $world = ftWorld('IT', 'CH');
    $api = ftApi($world);

    $order = $api->postJson('/api/v1/sales/orders', [
        'contact_id' => $world['contact']->id,
        'date' => now()->toDateString(),
        'currency' => 'EUR',
        'incoterm' => 'DDP',
        'incoterm_place' => 'Bern',
        'items' => [['product_id' => $world['product']->id, 'description' => 'Pump', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
    ])->assertCreated()->assertJsonPath('data.incoterm', 'DDP')->assertJsonPath('data.incoterm_place', 'Bern');

    $api->postJson('/api/v1/sales/orders', [
        'contact_id' => $world['contact']->id, 'date' => now()->toDateString(), 'currency' => 'EUR', 'incoterm' => 'BAD',
        'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 0]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['incoterm']);

    $api->postJson('/api/v1/sales/orders/'.$order->json('data.id').'/invoice')->assertCreated()->assertJsonPath('data.incoterm', 'DDP');
});
