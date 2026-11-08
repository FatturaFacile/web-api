<?php

declare(strict_types=1);

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPegging;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->fg = mfgItem($this, 'FG-MRP', 'finished_good', attributes: ['lead_time_days' => 3, 'min_lot_size' => '5', 'lot_size_multiple' => '5', 'product_id' => mfgProduct($this, 'FG-MRP')]);
    $this->rm = mfgItem($this, 'RM-MRP', 'raw_material', '1.50', ['lead_time_days' => 7, 'safety_stock' => '10', 'min_lot_size' => '50', 'max_lot_size' => '60', 'product_id' => mfgProduct($this, 'RM-MRP')]);
    mfgStock($this->fg, $this->warehouse, '4', '0');
    mfgStock($this->rm, $this->warehouse, '30', '1.50');

    $bomId = $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $this->fg->id, 'name' => 'FG', 'components' => [['component_item_id' => $this->rm->id, 'quantity_per' => '2']]])->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();

    // Demand of next month: a confirmed sales order line of 20 on the 6th, a forecast of 30 on the 9th (20 of it is the
    // sales demand already counted); an open purchase order of 15 raw material due in two days.
    $this->d1 = CarbonImmutable::now()->startOfMonth()->addMonth()->addDays(5);
    $this->d2 = $this->d1->addDays(3);
    mfgSalesDemand($this, (string) $this->fg->product_id, '20', $this->d1->toDateString());
    mfgPurchaseReceipt($this, (string) $this->rm->product_id, '15', now()->addDays(2)->toDateString());
    $this->postJson('/api/v1/manufacturing/mrp/forecasts', ['mfg_item_id' => $this->fg->id, 'forecast_date' => $this->d2->toDateString(), 'quantity' => '30'])->assertCreated();
});

/**
 * A catalog product of the company.
 */
function mfgProduct(object $test, string $sku): string
{
    $id = (string) Str::uuid7();
    DB::table('products')->insert(['id' => $id, 'tenant_id' => $test->tenant->id, 'sku' => $sku, 'name' => $sku, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/**
 * A confirmed sales order with one line of the product.
 */
function mfgSalesDemand(object $test, string $productId, string $quantity, string $dueDate, string $status = 'confirmed'): void
{
    $orderId = (string) Str::uuid7();
    DB::table('sales_orders')->insert(['id' => $orderId, 'tenant_id' => $test->tenant->id, 'contact_id' => $test->contact->id, 'order_number' => 'SO-'.Str::random(6), 'date' => now()->toDateString(), 'due_date' => $dueDate, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_order_items')->insert(['id' => (string) Str::uuid7(), 'sales_order_id' => $orderId, 'product_id' => $productId, 'description' => 'Line', 'quantity' => $quantity, 'quantity_delivered' => 0, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A sent purchase order with one open line of the product.
 */
function mfgPurchaseReceipt(object $test, string $productId, string $quantity, string $dueDate): void
{
    $orderId = (string) Str::uuid7();
    DB::table('purchase_orders')->insert(['id' => $orderId, 'tenant_id' => $test->tenant->id, 'supplier_id' => $test->contact->id, 'order_number' => 'PO-'.Str::random(6), 'date' => now()->toDateString(), 'delivery_due_date' => $dueDate, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_order_items')->insert(['id' => (string) Str::uuid7(), 'purchase_order_id' => $orderId, 'product_id' => $productId, 'description' => 'Line', 'quantity' => $quantity, 'quantity_received' => 0, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * Runs MRP over the next two months through the API.
 */
function mfgRunMrp(object $test): string
{
    return (string) $test->postJson('/api/v1/manufacturing/mrp/runs', ['horizon_start_date' => now()->toDateString(), 'horizon_end_date' => now()->addMonths(2)->endOfMonth()->toDateString()])
        ->assertCreated()->assertJsonPath('data.status', 'completed')->json('data.id');
}

test('MRP nets sales, consumed forecasts, safety stock and dependent demand level by level with lot sizing', function (): void {
    $runId = mfgRunMrp($this);

    $make = MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('mfg_item_id', $this->fg->id)->orderBy('due_date')->get();
    // Day 1: 4 on hand - 20 sold = -16, lot of 20 (multiple of 5); day 2: 4 - 10 (forecast 30 less 20 sold) = -6, lot of 10.
    expect($make->pluck('quantity')->all())->toBe(['20.0000', '10.0000'])
        ->and($make->pluck('net_requirement')->all())->toBe(['16.0000', '6.0000'])
        ->and($make->pluck('due_date')->map->toDateString()->all())->toBe([$this->d1->toDateString(), $this->d2->toDateString()])
        ->and($make[0]->start_date->toDateString())->toBe($this->d1->subDays(3)->toDateString())
        ->and($make[0]->order_type)->toBe('make')
        ->and($make[0]->bom_level)->toBe(0)
        ->and(MfgMrpPegging::query()->where('planned_order_id', $make[0]->id)->where('demand_type', 'sales_order_line')->value('pegged_quantity'))->toBe('16.0000');

    // Raw material: 30 on hand + 15 ordered - 40 for the first make order = 5, below the safety stock of 10: lot of 50.
    $buy = MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('mfg_item_id', $this->rm->id)->get();
    expect($buy)->toHaveCount(1)
        ->and($buy[0]->order_type)->toBe('buy')
        ->and($buy[0]->quantity)->toBe('50.0000')
        ->and($buy[0]->bom_level)->toBe(1)
        ->and($buy[0]->due_date->toDateString())->toBe($this->d1->subDays(3)->toDateString())
        ->and($buy[0]->start_date->toDateString())->toBe($this->d1->subDays(10)->toDateString());

    $this->getJson("/api/v1/manufacturing/mrp/runs/{$runId}")->assertOk()
        ->assertJsonPath('data.total_planned_orders', 3)
        ->assertJsonPath('data.total_shortages', 2)
        ->assertJsonCount(3, 'data.planned_orders');
    $shortages = collect($this->getJson("/api/v1/manufacturing/mrp/shortages?mrp_run_id={$runId}")->assertOk()->json('data'))->keyBy('mfg_item_id');
    expect($shortages[$this->rm->id]['gross_requirement'])->toBe('60.0000')
        ->and($shortages[$this->rm->id]['scheduled_receipts'])->toBe('15.0000')
        ->and($shortages[$this->rm->id]['recommended_action'])->toBe('purchase_order')
        ->and($shortages[$this->fg->id]['available_inventory'])->toBe('4.0000');
});

test('make orders are converted into production orders, purchase suggestions are linked by Procurement', function (): void {
    $runId = mfgRunMrp($this);
    $make = MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('order_type', 'make')->orderBy('due_date')->firstOrFail();
    $buy = MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('order_type', 'buy')->firstOrFail();

    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$make->id}/convert")->assertCreated()
        ->assertJsonPath('data.status', 'planned')
        ->assertJsonPath('data.target_quantity', '20.0000')
        ->assertJsonPath('data.planned_order_id', $make->id);
    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$make->id}/convert")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$buy->id}/convert")->assertUnprocessable()->assertJsonValidationErrors(['order_type']);

    $this->getJson('/api/v1/manufacturing/mrp/purchase-suggestions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $buy->id);
    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$buy->id}/procurement-link", ['procurement_document_type' => 'invoice'])->assertUnprocessable()->assertJsonValidationErrors(['procurement_document_type', 'procurement_document_id']);
    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$buy->id}/procurement-link", ['procurement_document_type' => 'purchase_order', 'procurement_document_id' => (string) Str::uuid7()])
        ->assertOk()->assertJsonPath('data.status', 'converted');
    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$make->id}/procurement-link", ['procurement_document_type' => 'purchase_order', 'procurement_document_id' => (string) Str::uuid7()])->assertUnprocessable();
    $this->getJson('/api/v1/manufacturing/mrp/purchase-suggestions')->assertOk()->assertJsonCount(0, 'data');

    // A new run supersedes the open planned order and counts the converted production order as a receipt.
    $second = mfgRunMrp($this);
    expect(MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('status', 'superseded')->count())->toBe(1)
        ->and(MfgMrpPlannedOrder::query()->where('mrp_run_id', $second)->where('mfg_item_id', $this->fg->id)->pluck('quantity')->all())->toBe(['10.0000']);

    $this->getJson("/api/v1/manufacturing/mrp/planned-orders?mrp_run_id={$second}&order_type=make")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/manufacturing/mrp/runs')->assertOk()->assertJsonCount(2, 'data');
});

test('forecasts are listed and deleted, and MRP validates its horizon and items', function (): void {
    $forecastId = $this->getJson('/api/v1/manufacturing/mrp/forecasts?mfg_item_id='.$this->fg->id)->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    $this->postJson('/api/v1/manufacturing/mrp/forecasts', ['mfg_item_id' => $this->fg->id, 'forecast_date' => 'soon', 'quantity' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['forecast_date', 'quantity']);
    $this->deleteJson("/api/v1/manufacturing/mrp/forecasts/{$forecastId}")->assertNoContent();
    $this->getJson('/api/v1/manufacturing/mrp/forecasts')->assertOk()->assertJsonCount(0, 'data');

    $foreign = mfgItem($this, 'FG-FOREIGN', 'finished_good', tenant: $this->otherTenant);
    $this->postJson('/api/v1/manufacturing/mrp/runs', ['horizon_start_date' => now()->toDateString(), 'horizon_end_date' => now()->addYears(3)->toDateString(), 'item_ids' => [$foreign->id]])
        ->assertUnprocessable()->assertJsonValidationErrors(['horizon_end_date', 'item_ids.0']);

    // Without the forecast only the sales order line is planned.
    $runId = mfgRunMrp($this);
    expect(MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('mfg_item_id', $this->fg->id)->pluck('quantity')->all())->toBe(['20.0000']);
});

test('an item below its safety stock with no demand is replenished from the horizon start', function (): void {
    MfgItem::query()->whereKey($this->fg->id)->update(['safety_stock' => '6']);
    DB::table('sales_orders')->update(['status' => 'cancelled']);
    $this->getJson('/api/v1/manufacturing/mrp/forecasts')->assertOk();
    DB::table('mfg_demand_forecasts')->delete();

    $runId = mfgRunMrp($this);
    $planned = MfgMrpPlannedOrder::query()->where('mrp_run_id', $runId)->where('mfg_item_id', $this->fg->id)->firstOrFail();
    expect($planned->quantity)->toBe('5.0000')
        ->and($planned->net_requirement)->toBe('2.0000')
        ->and($planned->due_date->toDateString())->toBe(now()->toDateString())
        ->and(MfgMrpPegging::query()->where('planned_order_id', $planned->id)->value('demand_type'))->toBe('safety_stock');
});
