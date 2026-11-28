<?php

declare(strict_types=1);

use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingReason;
use App\Models\StandardText;
use App\Models\UnitOfMeasure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/LocalizationSupport.php';

beforeEach(function (): void {
    locSetUp($this);
    $this->product = locInTenant($this->tenant, fn (): Product => Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'CONS-1', 'name' => 'Consulenza']));
});

it('lists the translations of a product across the active locales with the completeness', function (): void {
    $response = $this->getJson("/api/v1/products/{$this->product->id}/translations")->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resource', 'products')
        ->assertJsonPath('data.fields', ['name', 'description'])
        ->assertJsonPath('data.active_locales', ['it-CH', 'de-CH', 'fr-CH', 'en'])
        ->assertJsonPath('data.completeness.filled', 0)
        ->assertJsonPath('data.completeness.total', 8)
        ->assertJsonPath('data.completeness.percent', 0);

    expect($response->json('data.locales'))->toHaveCount(4)
        ->and($response->json('data.locales.1'))->toMatchArray(['locale' => 'de-CH', 'filled' => 0, 'total' => 2, 'percent' => 0]);
});

it('saves translations per locale and reports the completeness of each language', function (): void {
    $this->putJson("/api/v1/products/{$this->product->id}/translations", ['translations' => [
        'de-CH' => ['name' => 'Beratung', 'description' => 'Fachberatung'],
        'en' => ['name' => 'Consulting'],
    ]])->assertOk()
        ->assertJsonPath('data.completeness.filled', 3)
        ->assertJsonPath('data.completeness.percent', 38)
        ->assertJsonPath('data.locales.1.percent', 100)
        ->assertJsonPath('data.locales.3.percent', 50)
        ->assertJsonPath('data.locales.3.values.name', 'Consulting')
        ->assertJsonPath('data.locales.3.values.description', null);

    expect(DB::table('product_translations')->where('product_id', $this->product->id)->count())->toBe(2)
        ->and(DB::table('product_translations')->where('locale', 'de-CH')->value('tenant_id'))->toBe($this->tenant->id);
});

it('updates a locale in place and removes the row of a locale emptied by the user', function (): void {
    $url = "/api/v1/products/{$this->product->id}/translations";

    $this->putJson($url, ['translations' => ['de-CH' => ['name' => 'Beratung']]])->assertOk();
    $this->putJson($url, ['translations' => ['de-CH' => ['name' => 'Beratung neu']]])->assertOk()->assertJsonPath('data.locales.1.values.name', 'Beratung neu');

    expect(DB::table('product_translations')->count())->toBe(1);

    $this->putJson($url, ['translations' => ['de-CH' => ['name' => '', 'description' => null]]])->assertOk()->assertJsonPath('data.completeness.filled', 0);

    expect(DB::table('product_translations')->count())->toBe(0);
});

it('translates the other master data too', function (): void {
    $category = locInTenant($this->tenant, fn (): ProductCategory => ProductCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Servizi']));
    $unit = locInTenant($this->tenant, fn (): UnitOfMeasure => UnitOfMeasure::create(['tenant_id' => $this->tenant->id, 'code' => 'ORA', 'name' => 'Ora']));
    $term = locInTenant($this->tenant, fn (): PaymentTerm => PaymentTerm::create(['tenant_id' => $this->tenant->id, 'code' => '30GG', 'name' => '30 giorni', 'days' => 30]));

    $this->putJson("/api/v1/product-categories/{$category->id}/translations", ['translations' => ['fr-CH' => ['name' => 'Services']]])->assertOk();
    $this->putJson("/api/v1/units-of-measure/{$unit->id}/translations", ['translations' => ['de-CH' => ['name' => 'Stunde', 'symbol' => 'Std.']]])->assertOk()->assertJsonPath('data.locales.1.percent', 100);
    $this->putJson("/api/v1/payment-terms/{$term->id}/translations", ['translations' => ['de-CH' => ['invoice_clause' => 'Zahlbar innert 30 Tagen']]])->assertOk();

    // The name of a payment term translation is mandatory: the record name stands in for it
    expect(DB::table('payment_term_translations')->where('payment_term_id', $term->id)->first())->toMatchObject(['name' => '30 giorni', 'invoice_clause' => 'Zahlbar innert 30 Tagen', 'locale' => 'de-CH'])
        ->and(DB::table('product_category_translations')->value('name'))->toBe('Services')
        ->and(DB::table('unit_of_measure_translations')->value('symbol'))->toBe('Std.');
});

it('creates shipping reasons and standard texts that can be translated', function (): void {
    $reason = locInTenant($this->tenant, fn () => ShippingReason::create(['tenant_id' => $this->tenant->id, 'code' => 'VEN', 'name' => 'Vendita']));
    $text = locInTenant($this->tenant, fn () => StandardText::create(['tenant_id' => $this->tenant->id, 'code' => 'FOOTER', 'title' => 'Piè di pagina', 'body' => 'Grazie']));

    $this->putJson("/api/v1/shipping-reasons/{$reason->id}/translations", ['translations' => ['de-CH' => ['name' => 'Verkauf']]])->assertOk();
    $this->putJson("/api/v1/standard-texts/{$text->id}/translations", ['translations' => ['de-CH' => ['title' => 'Fusszeile', 'body' => 'Danke']]])->assertOk()
        ->assertJsonPath('data.fields', ['title', 'body'])
        ->assertJsonPath('data.locales.1.percent', 100);

    expect(DB::table('shipping_reason_translations')->value('name'))->toBe('Verkauf');
});

it('validates the payload: unknown locale, unknown field, wrong shape', function (array $payload): void {
    $this->putJson("/api/v1/products/{$this->product->id}/translations", $payload)->assertStatus(422)->assertJsonValidationErrors('translations');
})->with([
    'unknown locale' => [['translations' => ['es-ES' => ['name' => 'x']]]],
    'unknown field' => [['translations' => ['de-CH' => ['color' => 'x']]]],
    'no translations' => [['translations' => []]],
]);

it('hides the records of another tenant', function (): void {
    $foreign = locInTenant($this->otherTenant, fn (): Product => Product::create(['tenant_id' => $this->otherTenant->id, 'sku' => 'X-1', 'name' => 'Estraneo']));

    $this->getJson("/api/v1/products/{$foreign->id}/translations")->assertNotFound();
    $this->putJson("/api/v1/products/{$foreign->id}/translations", ['translations' => ['de-CH' => ['name' => 'x']]])->assertNotFound();
    $this->getJson('/api/v1/products/'.Str::uuid().'/translations')->assertNotFound();
});

it('keeps a global record shared by every company read only', function (): void {
    $globalId = (string) Str::uuid();
    DB::table('units_of_measure')->insert(['id' => $globalId, 'tenant_id' => null, 'code' => 'KG', 'name' => 'Chilogrammo', 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson("/api/v1/units-of-measure/{$globalId}/translations")->assertOk();
    $this->putJson("/api/v1/units-of-measure/{$globalId}/translations", ['translations' => ['de-CH' => ['name' => 'Kilogramm']]])->assertForbidden();

    expect(DB::table('unit_of_measure_translations')->count())->toBe(0);
});

it('forbids a member who is not owner or administrator to write', function (): void {
    locActor($this, 'accountant');

    $this->getJson("/api/v1/products/{$this->product->id}/translations")->assertOk();
    $this->putJson("/api/v1/products/{$this->product->id}/translations", ['translations' => ['de-CH' => ['name' => 'x']]])->assertForbidden();
});

it('only exposes the translatable resources', function (): void {
    $this->getJson("/api/v1/invoices/{$this->product->id}/translations")->assertNotFound();
});

it('reflects the active locales of the company in the translation tabs', function (): void {
    $this->putJson('/api/v1/settings/localization', ['active_locales' => ['it-CH', 'en'], 'default_locale' => 'it-CH'])->assertOk();

    $this->getJson("/api/v1/products/{$this->product->id}/translations")->assertOk()
        ->assertJsonPath('data.active_locales', ['it-CH', 'en'])
        ->assertJsonPath('data.completeness.total', 4);
});

it('loads only the requested and the company locale of the translations of a page of products (no N+1)', function (): void {
    foreach (['A', 'B', 'C'] as $sku) {
        $product = locInTenant($this->tenant, fn (): Product => Product::create(['tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku]));

        foreach (['de-CH', 'fr-CH', 'en', 'it-CH'] as $locale) {
            DB::table('product_translations')->insert(['product_id' => $product->id, 'locale' => $locale, 'name' => $sku.' '.$locale, 'tenant_id' => $this->tenant->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    DB::enableQueryLog();
    $products = locInTenant($this->tenant, fn () => Product::query()->whereIn('sku', ['A', 'B', 'C'])->withTranslation('de-CH', 'en')->get());
    $names = $products->map(fn (Product $product): ?string => $product->getTranslation('name', 'de-CH', 'en'))->all();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($names)->toBe(['A de-CH', 'B de-CH', 'C de-CH'])
        ->and($products->first()->translations->pluck('locale')->sort()->values()->all())->toBe(['de-CH', 'en']);
});

it('falls back from the requested locale to its language, then to the company locale, then to any value', function (): void {
    $product = locInTenant($this->tenant, fn (): Product => Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'F-1', 'name' => 'F']));

    foreach (['de' => 'Deutsch', 'en' => 'English', 'it' => 'Italiano'] as $locale => $name) {
        DB::table('product_translations')->insert(['product_id' => $product->id, 'locale' => $locale, 'name' => $name, 'tenant_id' => $this->tenant->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $loaded = locInTenant($this->tenant, fn () => Product::query()->whereKey($product->id)->with('translations')->firstOrFail());

    expect($loaded->getTranslation('name', 'de-CH', 'en'))->toBe('Deutsch')
        ->and($loaded->getTranslation('name', 'fr-CH', 'en'))->toBe('English')
        ->and($loaded->getTranslation('name', 'fr-CH', 'es'))->not->toBeNull()
        ->and($loaded->getTranslation('description', 'de-CH', 'en'))->toBeNull();
});
