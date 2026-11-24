<?php

declare(strict_types=1);

use App\Actions\Customs\EvaluateCustomsOriginAction;
use App\Actions\Customs\GenerateInvoiceOriginDeclarationAction;
use App\Models\Contact;
use App\Models\CustomsTaricCode;
use App\Models\FreeTradeAgreement;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\CustomsAndPemOriginSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CustomsAndPemOriginSeeder::class);
});

/**
 * Builds a cross-border invoice whose lines are described as [net amount, origin type].
 *
 * @param  array<int, array{0: float, 1: string|null}>  $lines
 */
function customsInvoice(string $exporterCountry, string $importerCountry, string $currency, array $lines, array $tenantOverrides = []): Invoice
{
    $tenant = Tenant::create(array_merge([
        'name' => 'Exporter '.Str::random(4),
        'legal_name' => 'Exporter Legal',
        'country' => $exporterCountry,
        'default_currency' => $exporterCountry === 'CH' ? 'CHF' : 'EUR',
    ], $tenantOverrides));

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'company_name' => 'Importer',
        'type' => 'customer',
        'country' => $importerCountry,
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-'.Str::random(6),
        'date' => now()->toDateString(),
        'currency' => $currency,
    ]);

    foreach ($lines as [$amount, $originType]) {
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Goods',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
            'origin_type' => $originType,
        ]);
    }

    return $invoice->fresh();
}

function declare_origin(Invoice $invoice, string $locale = 'en', ?string $auth = null): array
{
    return app(GenerateInvoiceOriginDeclarationAction::class)->execute($invoice, $locale, $auth);
}

// --- Seeder -----------------------------------------------------------------

it('seeds agreements and customs codes idempotently without changing primary keys', function (): void {
    $agreementIds = FreeTradeAgreement::query()->orderBy('code')->pluck('id', 'code')->all();
    $codeCount = CustomsTaricCode::count();

    $this->seed(CustomsAndPemOriginSeeder::class);

    expect(FreeTradeAgreement::query()->orderBy('code')->pluck('id', 'code')->all())->toBe($agreementIds)
        ->and(CustomsTaricCode::count())->toBe($codeCount)
        ->and(FreeTradeAgreement::count())->toBe(5);
});

it('seeds consistent catalog data', function (): void {
    $chEu = FreeTradeAgreement::where('code', 'CH-EU-1972')->firstOrFail();

    expect($chEu->value_threshold_eur)->toBe('6000.00')
        ->and($chEu->value_threshold_chf)->toBe('10300.00')
        ->and($chEu->partner_countries)->toContain('CH', 'IT')
        ->and($chEu->declaration_templates)->toHaveKeys(['it', 'de', 'fr', 'en'])
        ->and($chEu->declaration_templates_no_auth)->toHaveKeys(['it', 'de', 'fr', 'en']);

    CustomsTaricCode::all()->each(function (CustomsTaricCode $code): void {
        expect(strlen($code->code))->toBe($code->level->digits())
            ->and($code->chapter)->toBe(substr($code->code, 0, 2))
            ->and($code->description)->toHaveKeys(['it', 'de', 'fr', 'en']);
    });
});

// --- TARIC validation & product persistence ----------------------------------

function customsProductApi(): object
{
    $tenant = Tenant::create(['name' => 'Italy SRL', 'legal_name' => 'Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => 'Admin', 'email' => Str::random(6).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    return (object) ['tenant' => $tenant, 'user' => $user];
}

function productPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'SKU-'.Str::random(5),
        'name' => 'Wrist watch',
        'unit_measure' => 'pz',
        'cost_price' => 10,
        'sale_price' => 20,
        'tax_rate' => 22,
    ], $overrides);
}

it('accepts 6, 8 and 10 digit taric codes and rejects every other shape', function (string $code, bool $valid): void {
    $ctx = customsProductApi();

    $response = $this->withHeader('X-Tenant-Id', $ctx->tenant->id)
        ->postJson('/api/v1/inventory/products', productPayload(['taric_code' => $code]));

    $valid ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('taric_code');
})->with([
    'six digits' => ['910121', true],
    'eight digits' => ['91012100', true],
    'ten digits' => ['9101210000', true],
    'five digits' => ['91012', false],
    'seven digits' => ['9101210', false],
    'nine digits' => ['910121000', false],
    'eleven digits' => ['91012100000', false],
    'letters' => ['91012A00', false],
    'dotted' => ['9101.21.00', false],
]);

it('persists origin data and derives the made-in label from the country', function (string $country, string $label): void {
    $ctx = customsProductApi();

    $this->withHeader('X-Tenant-Id', $ctx->tenant->id)
        ->postJson('/api/v1/inventory/products', productPayload([
            'taric_code' => '91012100',
            'country_of_origin' => strtolower($country),
            'origin_type' => 'preferential',
            'eur1_required' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.country_of_origin', $country)
        ->assertJsonPath('data.origin_type', 'preferential')
        ->assertJsonPath('data.made_in_label', $label)
        ->assertJsonPath('data.eur1_required', true);
})->with([
    'italy' => ['IT', 'Made in Italy'],
    'switzerland' => ['CH', 'Swiss Made'],
]);

it('keeps an explicit made-in label and rejects unknown origin types', function (): void {
    $ctx = customsProductApi();
    $headers = ['X-Tenant-Id' => $ctx->tenant->id];

    $this->withHeaders($headers)
        ->postJson('/api/v1/inventory/products', productPayload(['country_of_origin' => 'IT', 'made_in_label' => 'Handmade in Tuscany']))
        ->assertCreated()
        ->assertJsonPath('data.made_in_label', 'Handmade in Tuscany');

    $this->withHeaders($headers)
        ->postJson('/api/v1/inventory/products', productPayload(['origin_type' => 'unknown']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('origin_type');
});

it('fills the taric code from a catalog selection', function (): void {
    $ctx = customsProductApi();
    $catalog = CustomsTaricCode::where('code', '91012100')->firstOrFail();

    $this->withHeader('X-Tenant-Id', $ctx->tenant->id)
        ->postJson('/api/v1/inventory/products', productPayload(['customs_taric_code_id' => $catalog->id]))
        ->assertCreated()
        ->assertJsonPath('data.taric_code', '91012100');
});

// --- Catalog API ---------------------------------------------------------------

it('searches the customs catalog by code prefix and localized description', function (): void {
    $ctx = customsProductApi();
    $headers = ['X-Tenant-Id' => $ctx->tenant->id];

    $byPrefix = $this->withHeaders($headers)->getJson('/api/v1/catalog/customs-taric-codes?search=9101')->assertOk();
    expect(collect($byPrefix->json('data'))->pluck('code')->all())->toBe(['91012100']);

    $byText = $this->withHeaders($headers)->getJson('/api/v1/catalog/customs-taric-codes?search=artificial')->assertOk();
    expect(collect($byText->json('data'))->pluck('code')->all())->toContain('90213100');
});

it('derives level and chapter when creating a customs code and blocks non managers', function (): void {
    $ctx = customsProductApi();
    $headers = ['X-Tenant-Id' => $ctx->tenant->id];
    $payload = ['code' => '620342', 'description' => ['en' => 'Trousers']];

    $this->withHeaders($headers)->postJson('/api/v1/catalog/customs-taric-codes', $payload)
        ->assertCreated()
        ->assertJsonPath('data.level', 'HS6')
        ->assertJsonPath('data.chapter', '62');

    $this->withHeaders($headers)->postJson('/api/v1/catalog/customs-taric-codes', ['code' => '62034', 'description' => ['en' => 'x']])
        ->assertUnprocessable();

    $ctx->user->tenants()->updateExistingPivot($ctx->tenant->id, ['role' => 'member']);
    $this->withHeaders($headers)->postJson('/api/v1/catalog/customs-taric-codes', ['code' => '620343', 'description' => ['en' => 'x']])
        ->assertForbidden();
    $this->withHeaders($headers)->getJson('/api/v1/catalog/customs-taric-codes')->assertOk();
});

it('lets members with the customs.manage permission edit agreements', function (): void {
    $ctx = customsProductApi();
    $ctx->user->tenants()->updateExistingPivot($ctx->tenant->id, ['role' => 'member', 'permissions' => json_encode(['customs.manage'])]);
    $agreement = FreeTradeAgreement::where('code', 'EFTA-CONVENTION')->firstOrFail();

    $this->withHeader('X-Tenant-Id', $ctx->tenant->id)
        ->putJson("/api/v1/catalog/free-trade-agreements/{$agreement->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

// --- Origin evaluation ---------------------------------------------------------

it('applies the EUR threshold and approved exporter rules for IT to CH shipments', function (float $amount, bool $withAuth, bool $aboveThreshold, ?string $expectedFragment): void {
    $invoice = customsInvoice('IT', 'CH', 'EUR', [[$amount, 'preferential']], $withAuth ? ['customs_approved_exporter_number' => 'IT-AE-77'] : []);

    $result = declare_origin($invoice, 'en');

    expect($result['evaluation']->aboveThreshold)->toBe($aboveThreshold)
        ->and($result['evaluation']->dutyExemptionEligible)->toBeTrue();

    if ($expectedFragment === null) {
        expect($result['declaration_text'])->toBeNull();
    } else {
        expect($result['declaration_text'])->toContain($expectedFragment);
    }
})->with([
    'at the threshold without authorization' => [6000.00, false, false, 'European Union preferential origin'],
    'above the threshold without authorization' => [6000.01, false, true, null],
    'above the threshold with authorization' => [8000.00, true, true, 'authorization No IT-AE-77'],
    'below the threshold with authorization' => [500.00, true, false, 'authorization No IT-AE-77'],
]);

it('applies the CHF threshold for CH to IT shipments', function (float $amount, bool $above): void {
    $invoice = customsInvoice('CH', 'IT', 'CHF', [[$amount, 'preferential']]);

    $evaluation = app(EvaluateCustomsOriginAction::class)->execute($invoice);

    expect($evaluation->thresholdCurrency)->toBe('CHF')
        ->and($evaluation->threshold)->toBe(10300.0)
        ->and($evaluation->aboveThreshold)->toBe($above)
        ->and($evaluation->declarationAllowed)->toBe(! $above);
})->with([
    'at the threshold' => [10300.00, false],
    'above the threshold' => [10300.01, true],
]);

it('only counts preferential lines towards the threshold', function (): void {
    $invoice = customsInvoice('CH', 'IT', 'CHF', [[9000.00, 'preferential'], [50000.00, 'non_preferential'], [70000.00, null]]);

    $evaluation = app(EvaluateCustomsOriginAction::class)->execute($invoice);

    expect($evaluation->preferentialValue)->toBe(9000.0)
        ->and($evaluation->aboveThreshold)->toBeFalse();
});

it('renders the declaration placeholders in every supported language', function (string $locale, string $origin): void {
    $invoice = customsInvoice('CH', 'IT', 'CHF', [[20000.00, 'preferential']], ['customs_approved_exporter_number' => 'CH-AE-123']);

    $text = declare_origin($invoice, $locale)['declaration_text'];

    expect($text)->toContain('CH-AE-123')->toContain($origin)
        ->not->toContain('{customs_auth_number}')->not->toContain('{preferential_origin}');
})->with([
    'italian' => ['it', 'Svizzera'],
    'german' => ['de', 'Schweiz'],
    'french' => ['fr', 'Suisse'],
    'english' => ['en', 'Switzerland'],
]);

it('names the European Union as origin for EU exporters in every language', function (string $locale, string $origin): void {
    $invoice = customsInvoice('IT', 'CH', 'EUR', [[100.00, 'preferential']]);

    expect(declare_origin($invoice, $locale)['declaration_text'])->toContain($origin);
})->with([
    'italian' => ['it', 'Unione Europea'],
    'german' => ['de', 'Europäische Union'],
    'french' => ['fr', 'Union européenne'],
    'english' => ['en', 'European Union'],
]);

it('never grants duty exemption for non preferential goods, domestic sales or non partner countries', function (): void {
    $nonPreferential = customsInvoice('IT', 'CH', 'EUR', [[100.00, 'non_preferential']]);
    $domestic = customsInvoice('IT', 'IT', 'EUR', [[100.00, 'preferential']]);
    $outsidePem = customsInvoice('IT', 'US', 'EUR', [[100.00, 'preferential']]);

    foreach ([$nonPreferential, $domestic, $outsidePem] as $invoice) {
        $result = declare_origin($invoice);

        expect($result['evaluation']->dutyExemptionEligible)->toBeFalse()
            ->and($result['declaration_text'])->toBeNull();
    }
});

it('accepts an explicit authorization number above the threshold', function (): void {
    $invoice = customsInvoice('IT', 'CH', 'EUR', [[9000.00, 'preferential']]);

    expect(declare_origin($invoice, 'en')['declaration_text'])->toBeNull()
        ->and(declare_origin($invoice, 'en', 'MANUAL-1')['declaration_text'])->toContain('MANUAL-1');
});

it('previews without persisting and stores the declaration when applied', function (): void {
    $invoice = customsInvoice('CH', 'IT', 'CHF', [[500.00, 'preferential']]);
    $user = User::create(['name' => 'Admin', 'email' => Str::random(6).'@example.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($invoice->tenant_id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $headers = ['X-Tenant-Id' => $invoice->tenant_id];

    $this->withHeaders($headers)->postJson("/api/v1/sales/invoices/{$invoice->id}/evaluate-customs-origin", ['locale' => 'de'])
        ->assertOk()
        ->assertJsonPath('data.applied', false)
        ->assertJsonPath('data.duty_exemption_eligible', true);
    expect($invoice->fresh()->origin_declaration_text)->toBeNull();

    $this->withHeaders($headers)->postJson("/api/v1/sales/invoices/{$invoice->id}/evaluate-customs-origin", ['locale' => 'de', 'apply' => true])
        ->assertOk()
        ->assertJsonPath('data.applied', true);

    $stored = $invoice->fresh();
    expect($stored->origin_declaration_text)->toContain('Schweiz')
        ->and($stored->duty_exemption_eligible)->toBeTrue()
        ->and($stored->free_trade_agreement_id)->not->toBeNull();
});

// --- Snapshot immutability ------------------------------------------------------

it('snapshots product customs data on invoice lines and keeps it after the product changes', function (): void {
    $ctx = customsProductApi();
    $headers = ['X-Tenant-Id' => $ctx->tenant->id];
    fiscalCalendar($ctx->tenant, (int) now()->year);
    $contact = Contact::create(['tenant_id' => $ctx->tenant->id, 'company_name' => 'Importer', 'type' => 'customer', 'country' => 'CH']);
    $product = Product::create([
        'tenant_id' => $ctx->tenant->id, 'sku' => 'WATCH-1', 'name' => 'Watch', 'sale_price' => 100, 'tax_rate' => 22,
        'taric_code' => '91012100', 'country_of_origin' => 'IT', 'origin_type' => 'preferential', 'net_weight_kg' => 0.2,
    ]);

    $response = $this->withHeaders($headers)->postJson('/api/v1/sales/invoices', [
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-SNAP-1',
        'date' => now()->toDateString(),
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'incoterm' => 'DAP',
        'items' => [['product_id' => $product->id, 'description' => 'Watch', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ])->assertCreated();

    $product->update(['taric_code' => '84818099', 'country_of_origin' => 'CH', 'origin_type' => 'non_preferential', 'made_in_label' => 'Swiss Made']);

    $item = InvoiceItem::where('invoice_id', $response->json('data.id'))->firstOrFail();
    expect($item->taric_code)->toBe('91012100')
        ->and($item->country_of_origin)->toBe('IT')
        ->and($item->origin_type)->toBe('preferential')
        ->and($item->made_in_label)->toBe('Made in Italy');
});

// --- Cross-border readiness guard ----------------------------------------------

it('blocks issuing an export invoice without Incoterm and net weight, and reports readiness', function (): void {
    $ctx = customsProductApi();
    $headers = ['X-Tenant-Id' => $ctx->tenant->id];
    fiscalCalendar($ctx->tenant, (int) now()->year);
    $contact = Contact::create(['tenant_id' => $ctx->tenant->id, 'company_name' => 'Importer', 'type' => 'customer', 'country' => 'CH']);
    $product = Product::create(['tenant_id' => $ctx->tenant->id, 'sku' => 'NW-1', 'name' => 'Watch', 'sale_price' => 100, 'tax_rate' => 22, 'taric_code' => '91012100', 'country_of_origin' => 'IT']);
    $payload = [
        'contact_id' => $contact->id, 'invoice_type' => 'standard', 'invoice_number' => 'INV-GUARD-1', 'date' => now()->toDateString(), 'currency' => 'EUR', 'jurisdiction' => 'IT',
        'items' => [['product_id' => $product->id, 'description' => 'Watch', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]],
    ];

    $this->withHeaders($headers)->postJson('/api/v1/sales/invoices', $payload)->assertUnprocessable()->assertJsonValidationErrors(['incoterm', 'items.0.net_weight_missing']);

    $product->update(['net_weight_kg' => 0.2]);
    $id = $this->withHeaders($headers)->postJson('/api/v1/sales/invoices', [...$payload, 'incoterm' => 'FCA'])->assertCreated()->json('data.id');

    $this->withHeaders($headers)->getJson("/api/v1/sales/invoices/{$id}/cross-border-readiness")->assertOk()->assertJsonPath('data.ready', true)->assertJsonPath('data.regime.regime', 'it_export_art_8_dpr_633_72');
});

it('computes customs value and VAT regime through the foreign trade API', function (): void {
    $ctx = customsProductApi();
    $headers = ['X-Tenant-Id' => $ctx->tenant->id];

    $this->withHeaders($headers)->postJson('/api/v1/foreign-trade/customs-value', ['incoterm' => 'EXW', 'goods_value' => 1000, 'freight' => 80, 'insurance' => 20])->assertOk()->assertJsonPath('data.customs_value', 1100);
    $this->withHeaders($headers)->postJson('/api/v1/foreign-trade/customs-value', ['incoterm' => 'XXX', 'goods_value' => 1])->assertUnprocessable();
    $this->withHeaders($headers)->postJson('/api/v1/foreign-trade/vat-regime', ['tenant_country' => 'CH', 'counterparty_country' => 'IT'])->assertOk()->assertJsonPath('data.regime', 'ch_export_art_23_ltva');
});
