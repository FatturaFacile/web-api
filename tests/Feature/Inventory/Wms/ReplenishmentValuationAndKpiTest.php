<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Procurement\Models\PurchaseSuggestion;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    $this->warehouse = logisticsWarehouse();
    $this->bin = logisticsBin($this->warehouse);
});

test('reorder rules suggest the order up to the maximum and hand over to procurement', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->bin, $item, '4');

    $rule = $this->postJson('/api/v1/inventory/reorder-rules', [
        'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id,
        'min_stock_level' => 5, 'reorder_point' => 10, 'max_stock_level' => 40, 'economic_order_quantity' => 20,
    ])->assertCreated()->json();

    $handOff = $this->postJson('/api/v1/inventory/replenishment/hand-off')->assertOk()->json();
    expect($handOff['created'])->toBe(1)
        ->and(PurchaseSuggestion::query()->where('source', 'reorder_rule')->where('source_id', $rule['id'])->exists())->toBeTrue();

    wmsReceive(logisticsBin($this->warehouse, 'quarantine', bin: 'Q'), $item, '50');
    $suggestion = $this->getJson('/api/v1/inventory/replenishment/suggestions?only_needed=1')->assertOk()->json('data.0');

    // Quarantined stock is not available: 4 on hand, up to 40 => 36, more than the economic quantity.
    expect($suggestion['available'])->toBe('4.0000')
        ->and($suggestion['needs_replenishment'])->toBeTrue()
        ->and($suggestion['suggested_quantity'])->toBe('36.0000');

    $this->putJson("/api/v1/inventory/reorder-rules/{$rule['id']}", ['reorder_point' => 50])->assertStatus(422)->assertJsonValidationErrors('max_stock_level');
    $this->deleteJson("/api/v1/inventory/reorder-rules/{$rule['id']}")->assertNoContent();
    expect(StockReorderRule::query()->withTrashed()->count())->toBe(0);
});

test('reorder rules are validated, scoped to the company and closed to read-only roles', function (): void {
    $item = logisticsItem();

    $this->postJson('/api/v1/inventory/reorder-rules', [
        'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $item->id, 'min_stock_level' => 20, 'reorder_point' => 10, 'max_stock_level' => 40, 'economic_order_quantity' => 5,
    ])->assertStatus(422)->assertJsonValidationErrors('reorder_point');

    $foreignItem = wmsInOtherTenant(static fn () => logisticsItem());
    $this->postJson('/api/v1/inventory/reorder-rules', [
        'warehouse_id' => $this->warehouse->id, 'stock_item_id' => $foreignItem->id, 'min_stock_level' => 1, 'reorder_point' => 2, 'max_stock_level' => 3, 'economic_order_quantity' => 1,
    ])->assertStatus(422)->assertJsonValidationErrors('stock_item_id');

    $foreignRule = wmsInOtherTenant(static fn (): StockReorderRule => StockReorderRule::factory()->create(['tenant_id' => app(TenantContext::class)->getTenantId(), 'warehouse_id' => logisticsWarehouse()->id, 'stock_item_id' => logisticsItem()->id]));
    $this->putJson("/api/v1/inventory/reorder-rules/{$foreignRule->id}", ['economic_order_quantity' => 3])->assertNotFound();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson('/api/v1/inventory/replenishment/hand-off')->assertForbidden();
    $this->getJson('/api/v1/inventory/replenishment/suggestions')->assertOk();
});

test('the valuation at a date undoes later movements and follows the method of the category', function (): void {
    $fifo = StockItemCategory::create(['code' => 'FIFO', 'name' => 'Fifo', 'valuation_method' => 'fifo']);
    $average = StockItemCategory::create(['code' => 'AVG', 'name' => 'Media', 'valuation_method' => 'moving_average']);
    $fifoItem = logisticsItem(['tracking_type' => 'none', 'category_id' => $fifo->id, 'current_moving_average_cost' => '0']);
    $averageItem = logisticsItem(['tracking_type' => 'none', 'category_id' => $average->id, 'current_moving_average_cost' => '0']);

    $this->travelTo(now()->subDays(10));
    wmsReceive($this->bin, $fifoItem, '10', null, 2.0);
    wmsReceive($this->bin, $averageItem, '10', null, 2.0);
    $this->travelBack();
    $this->travelTo(now()->subDays(5));
    wmsReceive($this->bin, $fifoItem, '10', null, 4.0);
    wmsReceive($this->bin, $averageItem, '10', null, 4.0);
    $this->travelBack();

    $past = $this->getJson('/api/v1/inventory/valuation/at-date?date='.now()->subDays(7)->toDateString())->assertOk()->json();
    expect($past['total_value'])->toBe('40.00')->and(collect($past['items'])->pluck('quantity')->all())->toBe(['10.0000', '10.0000']);

    $today = collect($this->getJson('/api/v1/inventory/valuation/at-date?warehouse_id='.$this->warehouse->id.'&date='.now()->toDateString())->assertOk()->json('items'))->keyBy('stock_item_id');
    expect($today[$fifoItem->id]['unit_cost'])->toBe('3.0000')
        ->and($today[$fifoItem->id]['cost_source'])->toBe('fifo_layers')
        ->and($today[$averageItem->id]['unit_cost'])->toBe('3.0000')
        ->and($today[$averageItem->id]['cost_source'])->toBe('movements')
        ->and($today[$averageItem->id]['value'])->toBe('60.00');

    $this->getJson('/api/v1/inventory/valuation/at-date?date='.now()->addDay()->toDateString())->assertStatus(422);
    $this->getJson('/api/v1/inventory/valuation/summary?valuation_method=STANDARD')->assertOk()->assertJsonPath('items_count', 2);
});

test('the KPIs give turnover, coverage, dead stock and count accuracy from the ledger', function (): void {
    $moving = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);
    $dead = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);

    $this->travelTo(now()->subDays(40));
    wmsReceive($this->bin, $moving, '100', null, 1.0);
    wmsReceive($this->bin, $dead, '10', null, 1.0);
    $this->travelBack();
    $this->travelTo(now()->subDays(10));
    logisticsMove($moving, $this->bin, null, 30);
    $this->travelBack();

    $kpis = $this->getJson('/api/v1/inventory/kpis?'.http_build_query(['from' => now()->subDays(29)->toDateString(), 'to' => now()->toDateString(), 'dead_stock_days' => 20]))
        ->assertOk()->json();

    expect($kpis['consumption_value'])->toBe('30.00')
        ->and($kpis['stock_value_start'])->toBe('110.00')
        ->and($kpis['stock_value_end'])->toBe('80.00')
        ->and($kpis['inventory_turnover'])->toBe('0.3158')
        ->and($kpis['coverage_days'])->toBe('80.00')
        ->and($kpis['dead_stock']['items_count'])->toBe(1)
        ->and($kpis['dead_stock']['items'][0]['stock_item_id'])->toBe($dead->id)
        ->and($kpis['accuracy']['accuracy_percent'])->toBeNull();

    $this->getJson('/api/v1/inventory/kpis?from=2026-01-01&to=2026-01-31')->assertStatus(422)->assertJsonValidationErrors('dead_stock_days');
});
