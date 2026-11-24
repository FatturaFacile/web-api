<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Inventory\Models\InventoryBookExport;
use Modules\Inventory\Models\StockItem;
use Tests\Support\InventoryValuationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

    ['tenant' => $this->tenant, 'user' => $this->user] = Fx::actingTenantUser();
    $this->tenantId = (string) $this->tenant->id;
    ['warehouse' => $this->warehouse, 'bin' => $this->bin] = Fx::warehouseWithBin($this->tenantId);
});

/**
 * FIFO item: 10 @ 5.00 received in June, 4 issued in September; WAC item: 10 @ 6.00 received in June.
 *
 * @return array{fifo: StockItem, wac: StockItem}
 */
function seedInventoryBookStock(object $test): array
{
    $fifo = Fx::item($test->tenantId, 'fifo', ['sku' => 'BOOK-A-FIFO']);
    $wac = Fx::item($test->tenantId, 'weighted_average', ['sku' => 'BOOK-B-WAC']);

    Fx::receive($fifo, $test->bin, 10, 5.00);
    Fx::receive($wac, $test->bin, 10, 6.00);

    $test->travelTo(Carbon::parse('2026-09-10 10:00:00'));
    Fx::issue($fifo, $test->bin, 4);
    $test->travelTo(Carbon::parse('2026-11-15 10:00:00'));

    return ['fifo' => $fifo, 'wac' => $wac];
}

test('inventory book rows are valued per method and totals equal the sum of rows', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);
    seedInventoryBookStock($this);

    $response = $this->getJson('/api/v1/inventory/book?year=2026&format=json');

    $response->assertOk()
        ->assertJsonPath('meta.year', 2026)
        ->assertJsonPath('meta.as_of', '2026-12-31')
        ->assertJsonPath('meta.company_id', $this->tenantId)
        ->assertJsonCount(2, 'rows')
        ->assertJsonPath('rows.0.sku', 'BOOK-A-FIFO')
        ->assertJsonPath('rows.0.quantity', '6.0000')
        ->assertJsonPath('rows.0.unit_cost', '5.0000')
        ->assertJsonPath('rows.0.total_value', '30.00')
        ->assertJsonPath('rows.0.valuation_method', 'fifo')
        ->assertJsonPath('rows.0.warehouse_id', (string) $this->warehouse->id)
        ->assertJsonPath('rows.1.sku', 'BOOK-B-WAC')
        ->assertJsonPath('rows.1.total_value', '60.00')
        ->assertJsonPath('rows.1.valuation_method', 'weighted_average')
        ->assertJsonPath('totals.quantity', '16.0000')
        ->assertJsonPath('totals.total_value', '90.00')
        ->assertJsonPath('meta.method_breakdown.fifo.total_value', '30.00')
        ->assertJsonPath('meta.method_breakdown.weighted_average.total_value', '60.00');

    $sum = array_reduce($response->json('rows'), static fn (string $carry, array $row): string => bcadd($carry, $row['total_value'], 2), '0');
    expect($sum)->toBe($response->json('totals.total_value'));

    // No inventory journal posted yet: the full value is reported as difference.
    $response->assertJsonPath('reconciliation.gl_inventory_balance', '0.00')
        ->assertJsonPath('reconciliation.difference', '90.00');
});

test('inventory book reconciles against the GL inventory account balance', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);
    seedInventoryBookStock($this);

    $inventory = ChartOfAccount::create(['tenant_id' => $this->tenantId, 'account_code' => '1200', 'name' => 'Inventory', 'account_class' => 'asset', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]);
    $grir = ChartOfAccount::create(['tenant_id' => $this->tenantId, 'account_code' => '2010', 'name' => 'GR/IR', 'account_class' => 'liability', 'account_type' => 'liability', 'normal_balance' => 'credit', 'is_active' => true]);

    app(DoubleEntryPostingServiceInterface::class)->createAndPost([
        'tenant_id' => $this->tenantId,
        'fiscal_period_id' => FiscalPeriod::query()->where('tenant_id', $this->tenantId)->value('id'),
        'posting_date' => '2026-06-15',
        'description' => 'Opening inventory',
        'lines' => [
            ['account_id' => $inventory->id, 'debit_amount' => 90.0, 'credit_amount' => 0.0],
            ['account_id' => $grir->id, 'debit_amount' => 0.0, 'credit_amount' => 90.0],
        ],
    ]);

    $this->getJson('/api/v1/inventory/book?year=2026')
        ->assertOk()
        ->assertJsonPath('reconciliation.gl_inventory_balance', '90.00')
        ->assertJsonPath('reconciliation.difference', '0.00');
});

test('inventory book as of a past date rolls back later movements', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);
    seedInventoryBookStock($this);

    // Before the September issue the FIFO item still had 10 units.
    $this->getJson('/api/v1/inventory/book?year=2026&as_of=2026-07-01')
        ->assertOk()
        ->assertJsonPath('meta.as_of', '2026-07-01')
        ->assertJsonPath('rows.0.quantity', '10.0000')
        ->assertJsonPath('rows.0.total_value', '50.00')
        ->assertJsonPath('totals.total_value', '110.00');
});

test('inventory book CSV export uses semicolons, a fixed header and an attachment name', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);
    seedInventoryBookStock($this);

    $response = $this->get('/api/v1/inventory/book?year=2026&format=csv');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('inventory-book-2026.csv');

    $lines = preg_split('/\r\n/', trim($response->getContent()));
    expect($lines[0])->toBe('sku;name;uom;warehouse;lot;quantity;unit_cost;total_value;method')
        ->and($lines[1])->toBe('BOOK-A-FIFO;Valuation Item;PCE;'.$this->warehouse->code.';;6.0000;5.0000;30.00;fifo')
        ->and($lines)->toHaveCount(3);
});

test('inventory book returns 422 when the fiscal year does not exist', function (): void {
    $this->getJson('/api/v1/inventory/book?year=1999')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['year']);
});

test('inventory book returns 422 when as_of is outside the fiscal year', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);

    $this->getJson('/api/v1/inventory/book?year=2026&as_of=2027-01-15')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['as_of']);
});

test('inventory book returns 409 when require_closed is set and the period is still open', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);

    $this->getJson('/api/v1/inventory/book?year=2026&require_closed=true')->assertStatus(409);
});

test('inventory book of a closed fiscal year can be exported with require_closed', function (): void {
    Fx::fiscalYear($this->tenantId, 2026, 'closed', 'closed');
    seedInventoryBookStock($this);

    $this->getJson('/api/v1/inventory/book?year=2026&require_closed=1')
        ->assertOk()
        ->assertJsonPath('totals.total_value', '90.00');
});

test('persisted inventory book exports are immutable, checksummed and downloadable', function (): void {
    Fx::fiscalYear($this->tenantId, 2026, 'closed', 'closed');
    seedInventoryBookStock($this);

    $created = $this->postJson('/api/v1/inventory/book/exports', ['year' => 2026, 'require_closed' => true]);

    $created->assertCreated()
        ->assertJsonPath('year', 2026)
        ->assertJsonPath('rows_count', 2)
        ->assertJsonPath('totals.total_value', '90.00')
        ->assertJsonPath('reconciliation.difference', '90.00');

    /** @var InventoryBookExport $export */
    $export = InventoryBookExport::query()->findOrFail($created->json('id'));
    expect($created->json('checksum'))->toBe(hash('sha256', $export->payload))
        ->and(strlen((string) $created->json('checksum')))->toBe(64);

    $this->getJson('/api/v1/inventory/book/exports?year=2026')
        ->assertOk()
        ->assertJsonPath('data.0.id', $export->id);

    $download = $this->get('/api/v1/inventory/book/exports/'.$export->id.'/download');
    $download->assertOk();
    expect($download->headers->get('X-Content-Checksum'))->toBe($export->checksum)
        ->and($download->getContent())->toStartWith('sku;name;uom;warehouse;lot;quantity;unit_cost;total_value;method');

    expect(fn () => $export->update(['total_value' => 1]))->toThrow(LogicException::class);
});

test('inventory book export requires the inventory.book.export permission', function (): void {
    Fx::fiscalYear($this->tenantId, 2026);
    $viewer = Fx::memberOf($this->tenant, 'viewer', []);
    Fx::activate($this->tenant, $viewer);

    $this->getJson('/api/v1/inventory/book?year=2026')->assertOk();
    $this->get('/api/v1/inventory/book?year=2026&format=csv')->assertForbidden();
    $this->postJson('/api/v1/inventory/book/exports', ['year' => 2026])->assertForbidden();

    $exporter = Fx::memberOf($this->tenant, 'accountant', ['inventory.book.*']);
    Fx::activate($this->tenant, $exporter);
    $this->postJson('/api/v1/inventory/book/exports', ['year' => 2026])->assertCreated();
});
