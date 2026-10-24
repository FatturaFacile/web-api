<?php

declare(strict_types=1);

use App\Contracts\PdfGenerator;
use App\Enums\BarcodeType;
use App\Exceptions\BarcodeGenerationException;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\BarcodeService;
use App\Services\Inventory\LabelPresetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    $brand = ProductBrand::create(['tenant_id' => $this->tenant->id, 'name' => 'Acme']);

    $this->widget = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'WID-1', 'name' => 'Acme Widget', 'barcode' => '590123412345',
        'sale_price' => 10, 'tax_rate' => 22, 'brand_id' => $brand->id,
    ]);
    $this->pump = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'PUMP-2', 'name' => 'Premium Pump', 'barcode' => '4006381333931',
        'sale_price' => 49.9, 'tax_rate' => 22,
    ]);
    $this->skuOnly = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'SKU-ONLY', 'name' => 'Loose Part', 'sale_price' => 5, 'tax_rate' => 0,
    ]);
    $this->foreign = Product::create([
        'tenant_id' => $this->otherTenant->id, 'sku' => 'FOREIGN-1', 'name' => 'Foreign Product', 'sale_price' => 1, 'tax_rate' => 0,
    ]);
});

function lblApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

function barcodes(): BarcodeService
{
    return app(BarcodeService::class);
}

function barcodeFailure(callable $callback): BarcodeGenerationException
{
    try {
        $callback();
    } catch (BarcodeGenerationException $e) {
        return $e;
    }

    throw new RuntimeException('Expected a BarcodeGenerationException.');
}

/**
 * A generator that records what is rendered, standing in for the PDF engine.
 */
function labelCapture(): object
{
    $capture = new class implements PdfGenerator
    {
        /** @var array<int, array{view: string, data: array<string, mixed>}> */
        public array $calls = [];

        public function render(string $view, array $data = []): string
        {
            $this->calls[] = ['view' => $view, 'data' => $data];

            return '%PDF-fake';
        }

        public function html(): string
        {
            $call = $this->calls[array_key_last($this->calls)];

            return view($call['view'], $call['data'])->render();
        }
    };

    app()->instance(PdfGenerator::class, $capture);

    return $capture;
}

/**
 * @return array{0: float, 1: float} the page size in PostScript points read from the first PDF page
 */
function pdfPageSize(string $pdf): array
{
    preg_match('/\/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $match);

    return [(float) ($match[1] ?? 0), (float) ($match[2] ?? 0)];
}

function customSheet(array $overrides = []): array
{
    return array_merge([
        'page_width_mm' => 50, 'page_height_mm' => 30,
        'margin_top_mm' => 0, 'margin_right_mm' => 0, 'margin_bottom_mm' => 0, 'margin_left_mm' => 0,
        'rows' => 1, 'cols' => 1, 'label_width_mm' => 50, 'label_height_mm' => 30, 'gap_x_mm' => 0, 'gap_y_mm' => 0,
    ], $overrides);
}

function shelfPreset(array $overrides = []): array
{
    return array_merge(customSheet([
        'name' => 'Shelf strip 100 x 25 mm', 'page_width_mm' => 100, 'page_height_mm' => 25, 'label_width_mm' => 100, 'label_height_mm' => 25,
        'defaults' => ['font_size_pt' => 9, 'barcode_type' => 'qrcode', 'show_sku' => false],
    ]), $overrides);
}

// ------------------------------------------------------------ Barcode engine

test('EAN-13 values get their check digit and complete values are verified', function (): void {
    expect(barcodes()->normalize(BarcodeType::Ean13, '590123412345'))->toBe('5901234123457')
        ->and(barcodes()->normalize(BarcodeType::Ean13, '4006381333931'))->toBe('4006381333931')
        ->and(barcodes()->eanCheckDigit('590123412345'))->toBe(7)
        ->and(barcodes()->normalize(BarcodeType::Ean8, '9638507'))->toBe('96385074')
        ->and(barcodes()->normalize(BarcodeType::Ean8, '96385074'))->toBe('96385074');
});

test('invalid values raise a typed exception with a machine readable reason', function (BarcodeType $type, string $value, string $reason): void {
    $failure = barcodeFailure(fn () => barcodes()->normalize($type, $value));

    expect($failure->reason)->toBe($reason)->and($failure->getMessage())->toBe(__("labels.errors.barcode.{$reason}"))->and($failure->value)->toBe($value);
})->with([
    'empty value' => [BarcodeType::Code128, '', 'empty_value'],
    'EAN-13 with letters' => [BarcodeType::Ean13, '59012341234A', 'invalid_characters'],
    'EAN-13 too short' => [BarcodeType::Ean13, '59012341234', 'invalid_length'],
    'EAN-13 too long' => [BarcodeType::Ean13, '59012341234578', 'invalid_length'],
    'EAN-13 wrong check digit' => [BarcodeType::Ean13, '5901234123458', 'invalid_checksum'],
    'EAN-8 wrong length' => [BarcodeType::Ean8, '963850', 'invalid_length'],
    'EAN-8 wrong check digit' => [BarcodeType::Ean8, '96385075', 'invalid_checksum'],
    'Code 128 with accents' => [BarcodeType::Code128, 'Café-1', 'invalid_characters'],
    'Code 128 too long' => [BarcodeType::Code128, str_repeat('A', 81), 'invalid_length'],
    'QR code too long' => [BarcodeType::QrCode, str_repeat('Q', 1001), 'invalid_length'],
    'QR code with control characters' => [BarcodeType::QrCode, "bad\x01value", 'invalid_characters'],
]);

test('unsupported types and output formats are rejected', function (): void {
    expect(barcodeFailure(fn () => BarcodeType::fromInput('upc'))->reason)->toBe('unsupported_type')
        ->and(barcodeFailure(fn () => barcodes()->render(BarcodeType::Code128, 'ABC-1', 'gif'))->reason)->toBe('unsupported_format')
        ->and(BarcodeType::fromInput(' EAN13 '))->toBe(BarcodeType::Ean13);
});

test('every type renders as SVG and as PNG, as binary or base64 data URI', function (BarcodeType $type, string $value): void {
    $svg = barcodes()->svg($type, $value);
    $png = barcodes()->png($type, $value);
    $uri = barcodes()->pngDataUri($type, $value);

    expect($svg)->toContain('<svg')
        ->and(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($uri)->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr($uri, strlen('data:image/png;base64,'))))->toBe($png)
        ->and(barcodes()->render($type, $value, 'svg'))->toContain('<svg')
        ->and(substr(barcodes()->render($type, $value, 'png'), 0, 4))->toBe("\x89PNG");
})->with([
    'EAN-13' => [BarcodeType::Ean13, '590123412345'],
    'EAN-8' => [BarcodeType::Ean8, '9638507'],
    'Code 128' => [BarcodeType::Code128, 'WID-1 / test'],
    'QR code' => [BarcodeType::QrCode, 'https://example.com/products/1?ref=label'],
]);

// ------------------------------------------------------------ Barcode endpoint

test('the barcode endpoint returns SVG or PNG and exposes the final encoded value', function (): void {
    $svg = lblApi()->get("/api/v1/inventory/products/{$this->widget->id}/barcode?type=ean13&format=svg")->assertOk();
    expect($svg->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($svg->headers->get('X-Barcode-Value'))->toBe('5901234123457')
        ->and($svg->headers->get('X-Barcode-Type'))->toBe('ean13')
        ->and($svg->getContent())->toContain('<svg');

    $png = lblApi()->get("/api/v1/inventory/products/{$this->pump->id}/barcode?type=ean13&format=png&scale=3&height=80")->assertOk();
    expect($png->headers->get('Content-Type'))->toBe('image/png')
        ->and($png->headers->get('X-Barcode-Value'))->toBe('4006381333931')
        ->and(substr($png->getContent(), 0, 4))->toBe("\x89PNG");
});

test('the barcode endpoint defaults to Code 128 of the SKU when the product has no barcode', function (): void {
    $response = lblApi()->get("/api/v1/inventory/products/{$this->skuOnly->id}/barcode")->assertOk();

    expect($response->headers->get('X-Barcode-Value'))->toBe('SKU-ONLY')
        ->and($response->headers->get('X-Barcode-Type'))->toBe('code128')
        ->and($response->headers->get('Content-Type'))->toStartWith('image/svg+xml');

    $qr = lblApi()->get("/api/v1/inventory/products/{$this->skuOnly->id}/barcode?type=qrcode&format=png")->assertOk();
    expect(substr($qr->getContent(), 0, 4))->toBe("\x89PNG");
});

test('the barcode endpoint reports values the type cannot encode and bad parameters', function (): void {
    lblApi()->getJson("/api/v1/inventory/products/{$this->skuOnly->id}/barcode?type=ean13")
        ->assertUnprocessable()
        ->assertJsonPath('title', 'Barcode cannot be generated')
        ->assertJsonPath('detail', 'The value contains characters that the selected barcode type cannot encode.');

    foreach (['type=upc', 'format=gif', 'scale=0', 'scale=11', 'height=5'] as $query) {
        lblApi()->getJson("/api/v1/inventory/products/{$this->widget->id}/barcode?{$query}")->assertUnprocessable();
    }
});

test('the barcode endpoint only serves products of the current tenant', function (): void {
    lblApi()->getJson("/api/v1/inventory/products/{$this->foreign->id}/barcode")->assertNotFound();
    lblApi($this->otherTenant->id)->getJson("/api/v1/inventory/products/{$this->widget->id}/barcode")->assertNotFound();
    lblApi()->getJson('/api/v1/inventory/products/'.Str::uuid().'/barcode')->assertNotFound();
});

// ------------------------------------------------------------ Presets

test('the factory presets are available and described in millimetres', function (): void {
    $presets = collect(lblApi()->getJson('/api/v1/inventory/labels/presets')->assertOk()->json('data'))->keyBy('key');

    expect($presets->keys()->all())->toBe(['a4_3x8_65x33', 'a4_2x7_105x42', 'thermal_50x30', 'thermal_60x40'])
        ->and($presets['a4_3x8_65x33']['geometry'])->toMatchArray(['rows' => 8, 'cols' => 3, 'label_width_mm' => 65, 'label_height_mm' => 33.8, 'page_width_mm' => 210, 'page_height_mm' => 297])
        ->and($presets['a4_2x7_105x42']['geometry'])->toMatchArray(['rows' => 7, 'cols' => 2, 'label_width_mm' => 105, 'label_height_mm' => 42.4])
        ->and($presets['thermal_50x30']['geometry'])->toMatchArray(['rows' => 1, 'cols' => 1, 'label_width_mm' => 50, 'label_height_mm' => 30])
        ->and($presets['thermal_60x40']['geometry'])->toMatchArray(['rows' => 1, 'cols' => 1, 'label_width_mm' => 60, 'label_height_mm' => 40])
        ->and($presets->every(fn (array $preset): bool => $preset['system'] === true))->toBeTrue();
});

test('every factory preset fits on its own page', function (): void {
    foreach (config('labels.presets') as $key => $preset) {
        expect(app(LabelPresetRegistry::class)->config($preset)->fits())->toBeTrue("preset {$key} does not fit");
    }
});

// ------------------------------------------------------------ Printing with presets

test('a system preset prints one label per requested copy with name, SKU, brand, taxed price and barcode', function (): void {
    $capture = labelCapture();

    lblApi()->post('/api/v1/inventory/labels/print', [
        'layout_mode' => 'preset', 'preset' => 'a4_3x8_65x33',
        'content_options' => ['barcode_type' => 'ean13'],
        'items' => [['product_id' => $this->widget->id, 'quantity' => 3], ['product_id' => $this->pump->id, 'quantity' => 2]],
    ])->assertOk();

    $html = $capture->html();

    expect($capture->calls[0]['view'])->toBe('documents.labels.sheet')
        ->and(substr_count($html, 'data-cell="label"'))->toBe(5)
        ->and(substr_count($html, 'data-cell="empty"'))->toBe(0)
        ->and(substr_count($html, 'data:image/png;base64,'))->toBe(5)
        ->and(substr_count($html, 'Acme Widget'))->toBe(3)
        ->and(substr_count($html, 'Premium Pump'))->toBe(2)
        ->and($html)->toContain('SKU: WID-1')->toContain('Brand: Acme')->toContain('12.20 EUR')->toContain('60.88 EUR')->toContain('incl. VAT')
        ->toContain('5901234123457')->toContain('4006381333931')
        ->toContain('size: 210mm 297mm')->toContain('width: 65mm; height: 33.8mm')->toContain('font-size: 8pt');
});

test('content options switch parts of the label on and off and choose the price basis', function (): void {
    $capture = labelCapture();
    $print = fn (array $options) => lblApi()->post('/api/v1/inventory/labels/print', [
        'preset' => 'thermal_50x30', 'content_options' => $options, 'items' => [['product_id' => $this->widget->id, 'quantity' => 1]],
    ])->assertOk();

    $print(['price_includes_tax' => false, 'font_size_pt' => 10]);
    expect($capture->html())->toContain('10.00 EUR')->toContain('excl. VAT')->toContain('font-size: 10pt');

    $print(['show_price' => false, 'show_name' => false, 'show_sku' => false, 'show_barcode' => false]);
    expect($capture->html())->not->toContain('EUR')->not->toContain('Acme Widget')->not->toContain('SKU:')->not->toContain('data:image/png');

    $print(['barcode_type' => 'qrcode']);
    expect($capture->html())->toContain('class="matrix"')->not->toContain('barcode-text">');
});

test('the thermal presets render a single label per page', function (): void {
    $capture = labelCapture();

    lblApi()->post('/api/v1/inventory/labels/print', [
        'preset' => 'thermal_60x40', 'items' => [['product_id' => $this->widget->id, 'quantity' => 3]],
    ])->assertOk();

    expect(substr_count($capture->html(), 'class="page '))->toBe(3)->and(substr_count($capture->html(), 'page-break-after'))->toBeGreaterThan(0);
});

test('labels print as a real PDF that can be downloaded or shown inline', function (): void {
    $payload = ['preset' => 'a4_2x7_105x42', 'items' => [['product_id' => $this->widget->id, 'quantity' => 4]]];

    $download = lblApi()->post('/api/v1/inventory/labels/print', $payload)->assertOk();
    expect($download->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($download->headers->get('Content-Disposition'))->toMatch('/^attachment; filename="Labels-\d{8}-\d{6}\.pdf"$/')
        ->and(substr($download->getContent(), 0, 5))->toBe('%PDF-')
        ->and(pdfPageSize($download->getContent()))->each->toBeGreaterThan(500);

    expect(lblApi()->post('/api/v1/inventory/labels/print?inline=1', $payload)->headers->get('Content-Disposition'))->toStartWith('inline; filename="Labels-');
});

test('the PDF page size follows the layout: A4 for sheets, label size for thermal rolls', function (): void {
    $a4 = lblApi()->post('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'items' => [['product_id' => $this->widget->id, 'quantity' => 1]]])->getContent();
    [$width, $height] = pdfPageSize($a4);
    expect($width)->toEqualWithDelta(595.28, 1.0)->and($height)->toEqualWithDelta(841.89, 1.0);

    $thermal = lblApi()->post('/api/v1/inventory/labels/print', [
        'layout_mode' => 'custom', 'layout' => customSheet(), 'items' => [['product_id' => $this->widget->id, 'quantity' => 1]],
    ])->getContent();
    [$width, $height] = pdfPageSize($thermal);
    expect($width)->toEqualWithDelta(141.73, 1.0)->and($height)->toEqualWithDelta(85.04, 1.0);
});

// ------------------------------------------------------------ Custom layout

test('a custom layout prints with its own geometry', function (): void {
    $capture = labelCapture();

    lblApi()->postJson('/api/v1/inventory/labels/print', [
        'layout_mode' => 'custom',
        'layout' => customSheet(['page_width_mm' => 100, 'page_height_mm' => 60, 'rows' => 2, 'cols' => 2, 'label_width_mm' => 40, 'label_height_mm' => 25, 'gap_x_mm' => 5, 'gap_y_mm' => 2, 'margin_left_mm' => 5, 'margin_top_mm' => 4]),
        'items' => [['product_id' => $this->widget->id, 'quantity' => 5]],
    ])->assertOk();

    $html = $capture->html();

    expect(substr_count($html, 'data-cell="label"'))->toBe(5)
        ->and(substr_count($html, 'class="page '))->toBe(2)
        ->and($html)->toContain('size: 100mm 60mm')->toContain('width: 40mm; height: 25mm')->toContain('margin-right: 5mm')->toContain('margin-bottom: 2mm')
        ->toContain('margin: 4mm 0 0 5mm');
});

test('a custom layout must be complete, positive and fit on the page', function (array $layout, string $errorKey): void {
    labelCapture();

    lblApi()->postJson('/api/v1/inventory/labels/print', [
        'layout_mode' => 'custom', 'layout' => $layout, 'items' => [['product_id' => test()->widget->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors($errorKey);
})->with([
    'missing label width' => [fn () => array_diff_key(customSheet(), ['label_width_mm' => 1]), 'layout.label_width_mm'],
    'missing margins' => [fn () => array_diff_key(customSheet(), ['margin_top_mm' => 1]), 'layout.margin_top_mm'],
    'zero rows' => [fn () => customSheet(['rows' => 0]), 'layout.rows'],
    'negative gap' => [fn () => customSheet(['gap_x_mm' => -1]), 'layout.gap_x_mm'],
    'tiny label' => [fn () => customSheet(['label_height_mm' => 1]), 'layout.label_height_mm'],
    'columns wider than the page' => [fn () => customSheet(['cols' => 2]), 'layout'],
    'rows taller than the page' => [fn () => customSheet(['rows' => 2, 'gap_y_mm' => 1]), 'layout'],
    'margins push the grid out' => [fn () => customSheet(['margin_left_mm' => 5]), 'layout'],
])->skip(false);

test('a custom layout is required in custom mode', function (): void {
    lblApi()->postJson('/api/v1/inventory/labels/print', ['layout_mode' => 'custom', 'items' => [['product_id' => $this->widget->id, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('layout');
});

// ------------------------------------------------------------ Tenant presets

test('a tenant saves a custom preset and its default, then prints with both', function (): void {
    $saved = lblApi()->putJson('/api/v1/settings/preferences/labels', ['default_preset' => 'shelf_strip', 'custom_presets' => ['shelf_strip' => shelfPreset()]])->assertOk();
    expect($saved->json('data.default_preset'))->toBe('shelf_strip')->and($saved->json('data.custom_presets.shelf_strip.label_width_mm'))->toEqual(100);

    $this->tenant->refresh();
    expect($this->tenant->settings['preferences']['labels']['custom_presets']['shelf_strip']['name'])->toBe('Shelf strip 100 x 25 mm');
    expect(lblApi()->getJson('/api/v1/settings/preferences')->json('data.labels.default_preset'))->toBe('shelf_strip');

    $presets = collect(lblApi()->getJson('/api/v1/inventory/labels/presets')->json('data'))->keyBy('key');
    expect($presets['shelf_strip']['system'])->toBeFalse()->and($presets['shelf_strip']['default'])->toBeTrue()->and($presets['thermal_50x30']['default'])->toBeFalse();

    $capture = labelCapture();
    $items = [['product_id' => $this->widget->id, 'quantity' => 1]];

    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'shelf_strip', 'items' => $items])->assertOk();
    expect($capture->html())->toContain('size: 100mm 25mm')->toContain('font-size: 9pt')->toContain('class="matrix"')->not->toContain('SKU:');

    // The tenant default applies when no preset is named, and the request can still override the preset defaults.
    lblApi()->postJson('/api/v1/inventory/labels/print', ['items' => $items, 'content_options' => ['show_sku' => true, 'barcode_type' => 'code128']])->assertOk();
    expect($capture->html())->toContain('size: 100mm 25mm')->toContain('SKU: WID-1')->not->toContain('class="matrix"');
});

test('saving tenant label presets validates keys, geometry and the default', function (array $payload, string $errorKey): void {
    lblApi()->putJson('/api/v1/settings/preferences/labels', $payload)->assertUnprocessable()->assertJsonValidationErrors($errorKey);
})->with([
    'system key is reserved' => [fn () => ['custom_presets' => ['a4_3x8_65x33' => shelfPreset()]], 'custom_presets.a4_3x8_65x33'],
    'key with spaces and capitals' => [fn () => ['custom_presets' => ['Bad Key' => shelfPreset()]], 'custom_presets.Bad Key'],
    'grid does not fit' => [fn () => ['custom_presets' => ['too_wide' => shelfPreset(['cols' => 2])]], 'custom_presets.too_wide'],
    'missing geometry' => [fn () => ['custom_presets' => ['broken' => ['name' => 'Broken']]], 'custom_presets.broken.page_width_mm'],
    'missing name' => [fn () => ['custom_presets' => ['nameless' => array_diff_key(shelfPreset(), ['name' => 1])]], 'custom_presets.nameless.name'],
    'unsupported default barcode type' => [fn () => ['custom_presets' => ['odd' => shelfPreset(['defaults' => ['barcode_type' => 'ean8']])]], 'custom_presets.odd.defaults.barcode_type'],
    'unknown default preset' => [fn () => ['default_preset' => 'does_not_exist'], 'default_preset'],
    'too many presets' => [fn () => ['custom_presets' => collect(range(1, 21))->mapWithKeys(fn (int $i): array => ["preset_{$i}" => shelfPreset()])->all()], 'custom_presets'],
]);

test('the default may name a system preset and presets can be removed again', function (): void {
    lblApi()->putJson('/api/v1/settings/preferences/labels', ['default_preset' => 'thermal_60x40'])->assertOk();
    lblApi()->putJson('/api/v1/settings/preferences/labels', ['custom_presets' => ['shelf_strip' => shelfPreset()]])->assertOk();

    // Keeping the stored custom preset as default is allowed while it exists.
    lblApi()->putJson('/api/v1/settings/preferences/labels', ['default_preset' => 'shelf_strip'])->assertOk();

    lblApi()->putJson('/api/v1/settings/preferences/labels', ['custom_presets' => null, 'default_preset' => null])->assertOk();

    labelCapture();
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'shelf_strip', 'items' => [['product_id' => $this->widget->id, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('preset');
});

test('tenant presets are isolated from other tenants', function (): void {
    lblApi()->putJson('/api/v1/settings/preferences/labels', ['custom_presets' => ['shelf_strip' => shelfPreset()]])->assertOk();
    $foreignItems = [['product_id' => $this->foreign->id, 'quantity' => 1]];

    lblApi($this->otherTenant->id)->postJson('/api/v1/inventory/labels/print', ['preset' => 'shelf_strip', 'items' => $foreignItems])
        ->assertUnprocessable()->assertJsonValidationErrors('preset');

    expect(collect(lblApi($this->otherTenant->id)->getJson('/api/v1/inventory/labels/presets')->json('data'))->pluck('key')->all())->not->toContain('shelf_strip');
});

// ------------------------------------------------------------ Start offset

test('the start offset leaves the first cells empty on a partly used sheet', function (): void {
    $capture = labelCapture();

    lblApi()->postJson('/api/v1/inventory/labels/print', [
        'preset' => 'a4_3x8_65x33', 'start_offset' => 4, 'items' => [['product_id' => $this->widget->id, 'quantity' => 2]],
    ])->assertOk();

    $html = $capture->html();
    $cells = array_column($capture->calls[0]['data']['pages'][0], null);

    expect(substr_count($html, 'data-cell="empty"'))->toBe(4)
        ->and(substr_count($html, 'data-cell="label"'))->toBe(2)
        ->and(array_map(fn (?array $cell): bool => $cell === null, array_slice($cells, 0, 6)))->toBe([true, true, true, true, false, false])
        ->and($capture->calls[0]['data']['config']->startOffset)->toBe(4)
        ->and(preg_match_all('/data-cell="empty">\s*<\/div>/', $html))->toBe(4); // empty cells are blank boxes
});

test('labels overflow onto further pages after the offset', function (): void {
    $capture = labelCapture();

    lblApi()->postJson('/api/v1/inventory/labels/print', [
        'preset' => 'a4_3x8_65x33', 'start_offset' => 22, 'items' => [['product_id' => $this->widget->id, 'quantity' => 5]],
    ])->assertOk();

    $pages = $capture->calls[0]['data']['pages'];
    $html = $capture->html();

    expect($pages)->toHaveCount(2)
        ->and(count($pages[0]))->toBe(24)
        ->and(count(array_filter($pages[0])))->toBe(2)
        ->and(count($pages[1]))->toBe(3)
        ->and(substr_count($html, 'data-cell="empty"'))->toBe(22)
        ->and(preg_match_all('/class="page\s+break\b/', $html))->toBe(1);
});

test('the start offset must stay below the labels per page', function (): void {
    labelCapture();
    $items = [['product_id' => $this->widget->id, 'quantity' => 1]];

    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'start_offset' => 24, 'items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors('start_offset');
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'thermal_50x30', 'start_offset' => 1, 'items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors('start_offset');
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'start_offset' => -1, 'items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors('start_offset');
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'start_offset' => 23, 'items' => $items])->assertOk();
});

// ------------------------------------------------------------ Multi-tenant isolation

test('products of other tenants and unknown products are rejected', function (): void {
    labelCapture();

    lblApi()->postJson('/api/v1/inventory/labels/print', [
        'preset' => 'a4_3x8_65x33', 'items' => [['product_id' => $this->widget->id, 'quantity' => 1], ['product_id' => $this->foreign->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');

    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');

    $this->widget->delete();
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'items' => [['product_id' => $this->widget->id, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_id');
});

// ------------------------------------------------------------ Validation

test('the print request validates presets, quantities, options and totals', function (array $payload, string $errorKey): void {
    labelCapture();

    lblApi()->postJson('/api/v1/inventory/labels/print', array_merge(['preset' => 'a4_3x8_65x33', 'items' => [['product_id' => test()->widget->id, 'quantity' => 1]]], $payload))
        ->assertUnprocessable()->assertJsonValidationErrors($errorKey);
})->with([
    'unknown preset' => [['preset' => 'nope'], 'preset'],
    'invalid layout mode' => [['layout_mode' => 'freestyle'], 'layout_mode'],
    'no items' => [['items' => []], 'items'],
    'zero quantity' => [['items' => [['product_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 0]]], 'items.0.quantity'],
    'quantity above 500' => [['items' => [['product_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 501]]], 'items.0.quantity'],
    'fractional quantity' => [['items' => [['product_id' => '00000000-0000-0000-0000-000000000000', 'quantity' => 1.5]]], 'items.0.quantity'],
    'missing quantity' => [['items' => [['product_id' => '00000000-0000-0000-0000-000000000000']]], 'items.0.quantity'],
    'barcode type not offered for labels' => [['content_options' => ['barcode_type' => 'ean8']], 'content_options.barcode_type'],
    'font too small' => [['content_options' => ['font_size_pt' => 3]], 'content_options.font_size_pt'],
    'non boolean flag' => [['content_options' => ['show_price' => 'maybe']], 'content_options.show_price'],
]);

test('a request without preset needs a tenant default, and one request prints at most 2000 labels', function (): void {
    labelCapture();
    $items = [['product_id' => $this->widget->id, 'quantity' => 1]];

    lblApi()->postJson('/api/v1/inventory/labels/print', ['items' => $items])->assertUnprocessable()->assertJsonValidationErrors('preset');

    $many = array_fill(0, 5, ['product_id' => $this->widget->id, 'quantity' => 500]);
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'items' => $many])->assertUnprocessable()->assertJsonValidationErrors('items');
});

test('products that cannot be encoded as the chosen barcode are reported by SKU', function (): void {
    labelCapture();
    $items = [['product_id' => $this->widget->id, 'quantity' => 1], ['product_id' => $this->skuOnly->id, 'quantity' => 2]];

    $response = lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'content_options' => ['barcode_type' => 'ean13'], 'items' => $items])
        ->assertUnprocessable()
        ->assertJsonPath('title', 'Labels cannot be printed')
        ->assertJsonPath('detail', 'Some products cannot be encoded with the selected barcode type.');

    expect($response->json('invalid_params'))->toHaveCount(1)
        ->and($response->json('invalid_params.0.product_id'))->toBe($this->skuOnly->id)
        ->and($response->json('invalid_params.0.reason'))->toContain('SKU-ONLY');

    // The same products print fine with Code 128, which encodes the SKU.
    lblApi()->postJson('/api/v1/inventory/labels/print', ['preset' => 'a4_3x8_65x33', 'content_options' => ['barcode_type' => 'code128'], 'items' => $items])->assertOk();
});

test('label printing requires authentication', function (): void {
    $this->app['auth']->forgetGuards();

    $this->withHeader('X-Tenant-ID', $this->tenant->id)->getJson('/api/v1/inventory/labels/presets')->assertUnauthorized();
});
