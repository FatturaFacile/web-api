<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\UnitOfMeasure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Modules\ProductCatalog\Models\CatalogKitComponent;
use Modules\ProductCatalog\Models\CatalogPackaging;
use Modules\ProductCatalog\Services\CatalogExportService;
use Modules\ProductCatalog\Services\CatalogSpreadsheet;

require_once __DIR__.'/ProductCatalogSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    pcSetUp($this);
});

/**
 * A csv exchange file with the given header and rows (cells already in the order of the header).
 *
 * @param  list<string>  $header
 * @param  list<list<string>>  $rows
 */
function pcCsv(array $header, array $rows, string $name = 'catalog.csv'): UploadedFile
{
    $lines = [implode(',', $header)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(static fn (string $cell): string => str_contains($cell, ',') || str_contains($cell, '"') ? '"'.str_replace('"', '""', $cell).'"' : $cell, $row));
    }

    return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
}

/**
 * @return list<string>
 */
function pcHeader(): array
{
    return CatalogSpreadsheet::COLUMNS;
}

/**
 * A row of the exchange file from a few named cells (the rest empty).
 *
 * @param  array<string, string>  $cells
 * @return list<string>
 */
function pcRow(array $cells): array
{
    return array_map(static fn (string $column): string => $cells[$column] ?? '', CatalogSpreadsheet::COLUMNS);
}

function pcImport(object $test, UploadedFile $file, string $mode): TestResponse
{
    return $test->post('/api/v1/catalog/import', ['file' => $file, 'mode' => $mode], ['Accept' => 'application/json']);
}

it('publishes the schema of the exchange file', function (): void {
    $schema = $this->getJson('/api/v1/catalog/import/schema')->assertOk()->json('data');

    expect($schema['columns'])->toBe(CatalogSpreadsheet::COLUMNS)->and($schema['required'])->toBe(['sku', 'name'])
        ->and($schema['modes'])->toBe(['atomic', 'partial', 'dry_run']);
});

it('imports new products and updates existing ones by SKU', function (): void {
    pcProduct($this, ['sku' => 'OLD-1', 'name' => 'Old name', 'sale_price' => '5']);
    pcUnit('PZ');

    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'OLD-1', 'name' => 'New name', 'sale_price' => '7.5']),
        pcRow(['sku' => 'NEW-1', 'name' => 'Brand new', 'product_type' => 'service', 'stock_uom' => 'PZ', 'sale_price' => '99', 'cost_price' => '40', 'tax_rate' => '8.1']),
    ]), 'atomic')->assertOk()->json('data');

    expect($report['committed'])->toBeTrue()->and($report['created'])->toBe(1)->and($report['updated'])->toBe(1)->and($report['failed'])->toBe(0)->and($report['errors'])->toBe([]);

    $old = Product::query()->where('sku', 'OLD-1')->first();
    $new = Product::query()->where('sku', 'NEW-1')->first();

    expect($old->name)->toBe('New name')->and($old->sale_price)->toBe('7.5000')
        ->and($new->product_type)->toBe('service')->and($new->track_inventory)->toBeFalse()->and($new->unit_measure)->toBe('PZ')->and($new->tax_rate)->toBe('8.10');
});

it('keeps what the file leaves empty when it updates a product', function (): void {
    pcProduct($this, ['sku' => 'KEEP', 'name' => 'Keep me', 'description' => 'A description', 'sale_price' => '5']);

    pcImport($this, pcCsv(pcHeader(), [pcRow(['sku' => 'KEEP', 'name' => 'Kept', 'sale_price' => '6'])]), 'atomic')->assertOk();

    $product = Product::query()->where('sku', 'KEEP')->first();

    expect($product->description)->toBe('A description')->and($product->name)->toBe('Kept');
});

it('only checks the file in a dry run and reports what would happen', function (): void {
    pcProduct($this, ['sku' => 'OLD-1']);

    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'OLD-1', 'name' => 'Changed']),
        pcRow(['sku' => 'NEW-1', 'name' => 'Brand new']),
    ]), 'dry_run')->assertOk()->json('data');

    expect($report['committed'])->toBeFalse()->and($report['created'])->toBe(1)->and($report['updated'])->toBe(1)
        ->and(Product::query()->where('sku', 'NEW-1')->exists())->toBeFalse()
        ->and(Product::query()->where('sku', 'OLD-1')->value('name'))->not->toBe('Changed');
});

it('reports every error with its line, SKU and field', function (): void {
    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'GOOD-1', 'name' => 'Fine']),
        pcRow(['sku' => 'BAD 1', 'name' => '']),
        pcRow(['sku' => 'BAD-2', 'name' => 'Bad type', 'product_type' => 'gadget', 'tares_code' => '12', 'taric_code' => '1234', 'country_of_origin' => 'ZZZ']),
        pcRow(['sku' => 'BAD-3', 'name' => 'Bad numbers', 'sale_price' => 'abc', 'tax_rate' => '250']),
    ]), 'dry_run')->assertOk()->json('data');

    $fields = collect($report['errors'])->groupBy('line')->map(fn ($errors) => $errors->pluck('field')->all());

    expect($report['failed'])->toBe(3)
        ->and($fields[3])->toContain('sku', 'name')
        ->and($fields[4])->toContain('product_type', 'tares_code', 'taric_code', 'country_of_origin')
        ->and($fields[5])->toContain('sale_price', 'tax_rate')
        ->and($fields->has(2))->toBeFalse()
        ->and(collect($report['errors'])->firstWhere('line', 4)['sku'])->toBe('BAD-2');
});

it('saves nothing in atomic mode when a row is wrong', function (): void {
    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'GOOD-1', 'name' => 'Fine']),
        pcRow(['sku' => 'BAD-1', 'name' => 'Bad', 'product_type' => 'gadget']),
    ]), 'atomic')->assertOk()->json('data');

    expect($report['committed'])->toBeFalse()->and($report['failed'])->toBe(1)->and(Product::query()->count())->toBe(0);
});

it('saves the valid rows in partial mode and reports the others', function (): void {
    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'GOOD-1', 'name' => 'Fine']),
        pcRow(['sku' => 'BAD-1', 'name' => 'Bad', 'product_type' => 'gadget']),
        pcRow(['sku' => 'GOOD-2', 'name' => 'Fine too']),
    ]), 'partial')->assertOk()->json('data');

    expect($report['committed'])->toBeTrue()->and($report['created'])->toBe(2)->and($report['failed'])->toBe(1)
        ->and(Product::query()->pluck('sku')->sort()->values()->all())->toBe(['GOOD-1', 'GOOD-2']);
});

it('rolls an atomic import back when a rule of the database phase fails, naming the row', function (): void {
    pcProduct($this, ['sku' => 'TAKEN-1', 'barcode' => '4006381333931']);

    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'GOOD-1', 'name' => 'Fine']),
        pcRow(['sku' => 'CLASH-1', 'name' => 'Clash', 'barcode' => '4006381333931']),
    ]), 'atomic')->assertOk()->json('data');

    expect($report['committed'])->toBeFalse()->and($report['errors'][0]['line'])->toBe(3)->and($report['errors'][0]['sku'])->toBe('CLASH-1')
        ->and(Product::query()->where('sku', 'GOOD-1')->exists())->toBeFalse();
});

it('refuses a repeated SKU in the file', function (): void {
    $report = pcImport($this, pcCsv(pcHeader(), [pcRow(['sku' => 'DUP', 'name' => 'One']), pcRow(['sku' => 'dup', 'name' => 'Two'])]), 'dry_run')->assertOk()->json('data');

    expect($report['failed'])->toBe(1)->and($report['errors'][0]['line'])->toBe(3)->and($report['errors'][0]['field'])->toBe('sku');
});

it('refuses a file with a missing, an unknown or a repeated column, and an empty one', function (): void {
    pcImport($this, pcCsv(['sku', 'price'], [['A', '1']]), 'atomic')->assertStatus(422)->assertJsonPath('code', 'import_missing_columns');
    pcImport($this, pcCsv(['sku', 'name', 'colour'], [['A', 'a', 'red']]), 'atomic')->assertStatus(422)->assertJsonPath('code', 'import_unknown_columns');
    pcImport($this, pcCsv(['sku', 'name', 'name'], [['A', 'a', 'b']]), 'atomic')->assertStatus(422)->assertJsonPath('code', 'import_duplicate_columns');
    pcImport($this, UploadedFile::fake()->createWithContent('empty.csv', ''), 'atomic')->assertStatus(422)->assertJsonPath('code', 'import_empty_file');
});

it('refuses a file that is neither csv nor xlsx', function (): void {
    $this->post('/api/v1/catalog/import', ['file' => UploadedFile::fake()->createWithContent('catalog.txt', 'sku,name'), 'mode' => 'atomic'], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['file']);
});

it('reads a semicolon separated csv as well', function (): void {
    $file = UploadedFile::fake()->createWithContent('catalog.csv', "sku;name;sale_price\nSEMI-1;Semicolon;12.5\n");

    $report = pcImport($this, $file, 'atomic')->assertOk()->json('data');

    expect($report['created'])->toBe(1)->and(Product::query()->where('sku', 'SEMI-1')->value('sale_price'))->toBe('12.5000');
});

it('imports a variant under its parent, with its attributes and inherited data', function (): void {
    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'VAR-S', 'name' => 'Shirt S', 'parent_sku' => 'PARENT', 'attributes' => 'Size=S|Color=Red', 'price_delta' => '2', 'price_delta_type' => 'fixed']),
        pcRow(['sku' => 'PARENT', 'name' => 'Shirt', 'sale_price' => '20', 'cost_price' => '8', 'tax_rate' => '22', 'country_of_origin' => 'PT']),
    ]), 'atomic')->assertOk()->json('data');

    $variant = Product::query()->where('sku', 'VAR-S')->first();

    expect($report['created'])->toBe(2)->and($variant->is_variant)->toBeTrue()->and($variant->parent->sku)->toBe('PARENT')
        ->and($variant->sale_price)->toBe('22.0000')->and($variant->cost_price)->toBe('8.0000')->and($variant->optionAttributes())->toBe(['Size' => 'S', 'Color' => 'Red'])
        ->and($variant->tax_rate)->toBe('22.00');
});

it('refuses a variant whose parent is nowhere, and a variant that is a kit', function (): void {
    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'V-1', 'name' => 'Orphan', 'parent_sku' => 'GHOST']),
        pcRow(['sku' => 'PARENT', 'name' => 'Parent']),
        pcRow(['sku' => 'V-2', 'name' => 'Kit variant', 'parent_sku' => 'PARENT', 'kit_type' => 'phantom']),
    ]), 'dry_run')->assertOk()->json('data');

    expect(collect($report['errors'])->pluck('field', 'line')->sortKeys()->all())->toBe([2 => 'parent_sku', 4 => 'kit_type']);
});

it('imports packaging hierarchies and kit components, in the same file as the products', function (): void {
    pcUnit('PZ');
    pcUnit('PACK');
    pcUnit('CT');

    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'KIT-1', 'name' => 'Kit', 'kit_type' => 'phantom', 'kit_components' => 'TAPE:2;BOLT:4:optional']),
        pcRow(['sku' => 'TAPE', 'name' => 'Tape', 'stock_uom' => 'PZ', 'packagings' => 'PACK6|PACK|6|||;CARTON24|CT|4|PACK6|purchase|4006381333931']),
        pcRow(['sku' => 'BOLT', 'name' => 'Bolt']),
    ]), 'atomic')->assertOk()->json('data');

    $tape = Product::query()->where('sku', 'TAPE')->first();
    $levels = CatalogPackaging::query()->where('product_id', $tape->id)->get()->keyBy('code');
    $components = CatalogKitComponent::query()->where('kit_id', Product::query()->where('sku', 'KIT-1')->value('id'))->orderBy('sort_order')->get();

    expect($report['created'])->toBe(3)->and($levels['CARTON24']->quantity_base)->toBe('24.000000')->and($levels['CARTON24']->gtin)->toBe('4006381333931')
        ->and($levels['CARTON24']->parent_packaging_id)->toBe($levels['PACK6']->id)
        ->and($components)->toHaveCount(2)->and($components[0]->quantity)->toBe('2.000000')->and($components[1]->is_optional)->toBeTrue();
});

it('refuses unknown units, VAT codes of the wrong country, malformed structures and unknown components', function (): void {
    pcVat('CH_MWST_81', 'CH', '8.1');

    $report = pcImport($this, pcCsv(pcHeader(), [
        pcRow(['sku' => 'A', 'name' => 'A', 'stock_uom' => 'NOPE']),
        pcRow(['sku' => 'B', 'name' => 'B', 'vat_code_it' => 'CH_MWST_81']),
        pcRow(['sku' => 'C', 'name' => 'C', 'packagings' => 'broken']),
        pcRow(['sku' => 'D', 'name' => 'D', 'kit_type' => 'phantom', 'kit_components' => 'GHOST:1']),
        pcRow(['sku' => 'E', 'name' => 'E', 'kit_components' => 'A:1']),
        pcRow(['sku' => 'F', 'name' => 'F', 'attributes' => 'Size']),
    ]), 'dry_run')->assertOk()->json('data');

    expect(collect($report['errors'])->pluck('field', 'line')->sortKeys()->all())->toBe([2 => 'stock_uom', 3 => 'vat_code_it', 4 => 'packagings', 5 => 'kit_components', 6 => 'kit_components', 7 => 'attributes']);
});

it('neutralises formulas when it writes a file', function (): void {
    pcProduct($this, ['sku' => 'FORMULA', 'name' => '=HYPERLINK("http://evil")']);

    $path = app(CatalogExportService::class)->export('csv');
    $content = (string) file_get_contents($path);
    @unlink($path);

    expect($content)->toContain("'=HYPERLINK")->and($content)->not->toContain(',=HYPERLINK');
});

it('exports the catalog and imports it back without loss, as csv', function (): void {
    pcRoundTripFixture($this);
    $before = pcSnapshot();

    $path = app(CatalogExportService::class)->export('csv');
    pcWipe();

    $report = pcImport($this, new UploadedFile($path, 'catalog.csv', 'text/csv', null, true), 'atomic')->assertOk()->json('data');
    @unlink($path);

    expect($report['failed'])->toBe(0)->and($report['errors'])->toBe([])->and(pcSnapshot())->toBe($before);
});

it('exports the catalog and imports it back without loss, as xlsx', function (): void {
    pcRoundTripFixture($this);
    $before = pcSnapshot();

    $path = app(CatalogExportService::class)->export('xlsx');
    pcWipe();

    $report = pcImport($this, new UploadedFile($path, 'catalog.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), 'atomic')->assertOk()->json('data');
    @unlink($path);

    expect($report['failed'])->toBe(0)->and(pcSnapshot())->toBe($before);
});

it('exports variants right after their parent and writes the template header only', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'AAA']);
    pcProduct($this, ['sku' => 'ZZZ']);
    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s'], ['black']))->assertCreated();

    $skus = array_column(iterator_to_array(app(CatalogExportService::class)->rows(), false), 0);

    expect($skus)->toBe(['AAA', 'AAA-S-BLK', 'ZZZ']);

    $template = app(CatalogExportService::class)->template('csv');
    $lines = array_filter(explode("\n", trim(ltrim((string) file_get_contents($template), "\xEF\xBB\xBF"))));
    @unlink($template);

    expect($lines)->toHaveCount(1)->and(explode(',', (string) reset($lines)))->toBe(CatalogSpreadsheet::COLUMNS);
});

it('downloads the export and the template through the API', function (): void {
    pcProduct($this, ['sku' => 'DL-1']);

    $this->get('/api/v1/catalog/export?format=csv')->assertOk()->assertDownload('catalog.csv');
    $this->get('/api/v1/catalog/import/template?format=xlsx')->assertOk()->assertDownload('catalog-template.xlsx');
    $this->getJson('/api/v1/catalog/export?format=pdf')->assertStatus(422);
});

it('never imports into or exports from another company', function (): void {
    pcOtherTenantProduct($this, 'ELSEWHERE');
    pcProduct($this, ['sku' => 'MINE']);

    $skus = array_column(iterator_to_array(app(CatalogExportService::class)->rows(), false), 0);

    expect($skus)->toBe(['MINE']);

    pcImport($this, pcCsv(pcHeader(), [pcRow(['sku' => 'ELSEWHERE', 'name' => 'Mine now'])]), 'atomic')->assertOk();

    expect(Product::query()->where('sku', 'ELSEWHERE')->value('name'))->toBe('Mine now')
        ->and(pcInTenant($this->otherTenant, fn () => Product::query()->where('sku', 'ELSEWHERE')->value('name')))->toBe('Altrui');
});

it('keeps a read-only member from importing', function (): void {
    pcSetUp($this, 'accountant_readonly');

    pcImport($this, pcCsv(pcHeader(), [pcRow(['sku' => 'X', 'name' => 'X'])]), 'atomic')->assertForbidden();
    $this->get('/api/v1/catalog/export?format=csv')->assertOk();
});

/**
 * Products, a variant matrix, a packaging hierarchy, a kit and classified items for the round trip.
 */
function pcRoundTripFixture(object $test): void
{
    $it = pcVat('IVA22', 'IT', '22');
    $ch = pcVat('CH_MWST_81', 'CH', '8.1');
    $attributes = pcStandardAttributes($test);
    $parent = pcProduct($test, ['sku' => 'TSH', 'name' => 'T-shirt', 'sale_price' => '20', 'cost_price' => '8', 'unit_of_measure_id' => pcUnit('PZ'), 'tares_code' => '61091000', 'taric_code' => '610910', 'country_of_origin' => 'PT', 'vat_code_it_id' => $it, 'vat_code_ch_id' => $ch]);
    $test->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s', 'xl'], ['black']))->assertCreated();

    $tape = pcProduct($test, ['sku' => 'TAPE', 'name' => 'Tape', 'product_type' => 'consumable', 'unit_of_measure_id' => pcUnit('PZ'), 'barcode' => '4006381333931']);
    $test->putJson('/api/v1/catalog/products/'.$tape['id'].'/packagings', ['levels' => [
        ['code' => 'PACK6', 'uom_id' => pcUnit('PACK'), 'units_per_parent' => 6, 'role' => 'sales'],
        ['code' => 'CARTON24', 'uom_id' => pcUnit('CT'), 'units_per_parent' => 4, 'parent_code' => 'PACK6', 'role' => 'purchase'],
    ]])->assertOk();

    $kit = pcProduct($test, ['sku' => 'KIT', 'name' => 'Kit', 'kit_type' => 'phantom']);
    $test->putJson('/api/v1/catalog/products/'.$kit['id'].'/kit-components', ['components' => [
        ['component_id' => $tape['id'], 'quantity' => 2],
        ['component_id' => $parent['id'], 'quantity' => 1, 'is_optional' => true],
    ]])->assertOk();

    pcProduct($test, ['sku' => 'LIC', 'name' => 'Licence', 'product_type' => 'digital', 'sale_price' => '360']);
}

/**
 * What a round trip must preserve: the figures, types, units, classification, packagings and kits of every product, by SKU.
 *
 * @return array<string, array<string, mixed>>
 */
function pcSnapshot(): array
{
    $codes = UnitOfMeasure::query()->pluck('code', 'id');
    $skus = Product::query()->pluck('sku', 'id');
    $snapshot = [];

    foreach (Product::query()->orderBy('sku')->get() as $product) {
        $snapshot[$product->sku] = [
            'name' => $product->name,
            'type' => $product->product_type,
            'kit' => $product->kit_type,
            'parent' => $product->parent_id !== null ? $skus[$product->parent_id] : null,
            'unit' => $product->unit_of_measure_id !== null ? $codes[$product->unit_of_measure_id] : null,
            'barcode' => $product->barcode,
            'cost' => $product->cost_price,
            'price' => $product->sale_price,
            'tax' => $product->tax_rate,
            'tares' => $product->swiss_hs_code,
            'taric' => $product->taric_code,
            'origin' => $product->country_of_origin,
            'vat_it' => $product->vat_code_it_id,
            'vat_ch' => $product->vat_code_ch_id,
            'track' => $product->track_inventory,
            'attributes' => $product->optionAttributes(),
            'packagings' => CatalogPackaging::query()->where('product_id', $product->id)->orderBy('level')->get()->map(fn (CatalogPackaging $p): string => $p->code.'|'.$codes[$p->uom_id].'|'.$p->quantity_base.'|'.$p->role->value)->all(),
            'kit_lines' => CatalogKitComponent::query()->where('kit_id', $product->id)->orderBy('sort_order')->get()->map(fn (CatalogKitComponent $l): string => $skus[$l->component_id].':'.$l->quantity.':'.($l->is_optional ? 'optional' : ''))->all(),
        ];
    }

    return $snapshot;
}

/**
 * Removes every catalog product of the company (and with them variants, kits and packagings).
 */
function pcWipe(): void
{
    CatalogKitComponent::query()->forceDelete();
    CatalogPackaging::query()->forceDelete();
    Product::withTrashed()->forceDelete();
}
