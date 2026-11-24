<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\ForeignTrade\CrossBorderReadinessValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function ftContext(string $tenantCountry, string $contactCountry): array
{
    $tenant = Tenant::create(['name' => 'T '.Str::random(4), 'legal_name' => 'T', 'country' => $tenantCountry, 'default_currency' => 'EUR']);
    $contact = Contact::create(['tenant_id' => $tenant->id, 'company_name' => 'C', 'type' => 'customer', 'country' => $contactCountry]);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Goods', 'sku' => 'SKU-'.Str::random(5), 'price' => 10, 'net_weight_kg' => 1.5]);

    return [$tenant, $contact, $product];
}

it('does not require customs data on domestic documents', function (): void {
    [$tenant, $contact] = ftContext('IT', 'IT');

    $result = app(CrossBorderReadinessValidator::class)->check(['tenant_id' => $tenant->id, 'contact_id' => $contact->id], [['description' => 'x']]);

    expect($result['cross_border'])->toBeFalse()->and($result['ready'])->toBeTrue();
});

it('flags missing incoterm, tariff code, origin and weight on an export', function (): void {
    [$tenant, $contact] = ftContext('IT', 'CH');
    $bare = Product::create(['tenant_id' => $tenant->id, 'name' => 'Bare', 'sku' => 'B-'.Str::random(5), 'price' => 5]);

    $result = app(CrossBorderReadinessValidator::class)->check(
        ['tenant_id' => $tenant->id, 'contact_id' => $contact->id],
        [['product_id' => $bare->id]],
    );

    expect($result['ready'])->toBeFalse()
        ->and(array_column($result['issues'], 'code'))->toContain('incoterm_missing', 'hs_code_missing', 'origin_country_missing', 'net_weight_missing');
});

it('is ready once every cross-border datum is present', function (): void {
    [$tenant, $contact, $product] = ftContext('CH', 'IT');

    $result = app(CrossBorderReadinessValidator::class)->check(
        ['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'incoterm' => 'DAP'],
        [['product_id' => $product->id, 'taric_code' => '8501101000', 'country_of_origin' => 'CH']],
    );

    expect($result['ready'])->toBeTrue()->and($result['regime']['regime'])->toBe('ch_export_art_23_ltva');
});

it('blocks issuing an incomplete cross-border document', function (): void {
    [$tenant, $contact, $product] = ftContext('IT', 'DE');

    app(CrossBorderReadinessValidator::class)->assertReady(
        ['tenant_id' => $tenant->id, 'contact_id' => $contact->id],
        [['product_id' => $product->id]],
    );
})->throws(ValidationException::class);

it('checks a stored invoice', function (): void {
    [$tenant, $contact, $product] = ftContext('IT', 'CH');
    $invoice = Invoice::create(['tenant_id' => $tenant->id, 'contact_id' => $contact->id, 'invoice_number' => 'INV-'.Str::random(5), 'date' => now()->toDateString(), 'currency' => 'EUR', 'incoterm' => 'FCA']);
    InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'description' => 'g', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10, 'taric_code' => '8501101000', 'country_of_origin' => 'IT']);

    expect(app(CrossBorderReadinessValidator::class)->checkInvoice($invoice->fresh())['ready'])->toBeTrue();
});
