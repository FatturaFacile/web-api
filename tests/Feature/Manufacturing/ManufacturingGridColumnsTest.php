<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
});

/**
 * Creates a BOM with the given number of components through the API.
 *
 * @return string BOM id
 */
function gridBom(object $test, MfgItem $item, string $name, int $componentCount, string $revision = 'A'): string
{
    $components = [];
    foreach (range(1, $componentCount) as $n) {
        $components[] = ['component_item_id' => mfgItem($test, Str::slug($name)."-C{$n}", 'raw_material', '1.00')->id, 'quantity_per' => '1'];
    }

    return (string) $test->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $item->id, 'name' => $name, 'revision' => $revision, 'components' => $components])->assertCreated()->json('data.id');
}

test('the BOM grid sorts, filters and lists the calculated components count and the item code over the whole dataset', function (): void {
    $alpha = mfgItem($this, 'FG-ALPHA', 'finished_good');
    $beta = mfgItem($this, 'FG-BETA', 'finished_good');
    gridBom($this, $alpha, 'Alpha one', 1);
    gridBom($this, $alpha, 'Alpha three', 3, 'B');
    gridBom($this, $beta, 'Beta two', 2);

    $sorted = $this->getJson('/api/v1/manufacturing/boms?sort_by=components_count&sort_dir=desc&per_page=2')->assertOk();
    expect(collect($sorted->json('data'))->pluck('name')->all())->toBe(['Alpha three', 'Beta two'])
        ->and($sorted->json('data.0.components_count'))->toBe(3)
        ->and($sorted->json('data.0.item_code'))->toBe('FG-ALPHA')
        ->and($sorted->json('meta.total'))->toBe(3);

    $filtered = $this->getJson('/api/v1/manufacturing/boms?filter[components_count][]=2')->assertOk();
    expect($filtered->json('meta.total'))->toBe(1)->and($filtered->json('data.0.name'))->toBe('Beta two');

    $counts = collect($this->getJson('/api/v1/manufacturing/boms?grid_values=components_count')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($counts)->toBe([1 => 1, 2 => 1, 3 => 1]);

    $byItem = $this->getJson('/api/v1/manufacturing/boms?filter[mfgItem.item_code][]=FG-ALPHA&sort_by=mfgItem.item_code')->assertOk();
    expect($byItem->json('meta.total'))->toBe(2);

    $items = collect($this->getJson('/api/v1/manufacturing/boms?grid_values=mfgItem.item_code')->assertOk()->json('data'))->pluck('count', 'value')->all();
    expect($items)->toBe(['FG-ALPHA' => 2, 'FG-BETA' => 1]);
});

test('the BOM and production order lists search by number, name and item code', function (): void {
    $alpha = mfgItem($this, 'FG-ALPHA', 'finished_good');
    $beta = mfgItem($this, 'FG-BETA', 'finished_good');
    gridBom($this, $alpha, 'Gearbox', 1);
    gridBom($this, $beta, 'Housing', 1);

    expect($this->getJson('/api/v1/manufacturing/boms?search=gearbox')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/manufacturing/boms?search=fg-beta')->assertOk()->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/v1/manufacturing/boms?search=nothing-like-this')->assertOk()->json('meta.total'))->toBe(0)
        ->and($this->getJson('/api/v1/manufacturing/production-orders?search=nothing-like-this')->assertOk()->json('meta.total'))->toBe(0);
});

test('the MRP grids resolve the item code and run through real columns and relation paths', function (): void {
    $item = mfgItem($this, 'FG-MRP', 'finished_good');
    $run = MfgMrpRun::factory()->create(['tenant_id' => $this->tenant->id]);
    MfgMrpPlannedOrder::factory()->create(['tenant_id' => $this->tenant->id, 'mrp_run_id' => $run->id, 'mfg_item_id' => $item->id, 'order_type' => 'make', 'status' => 'planned']);
    MfgMrpShortage::factory()->create(['tenant_id' => $this->tenant->id, 'mrp_run_id' => $run->id, 'mfg_item_id' => $item->id]);

    $planned = $this->getJson('/api/v1/manufacturing/mrp/planned-orders?filter[mfgItem.item_code][]=FG-MRP&sort_by=due_date')->assertOk();
    expect($planned->json('meta.total'))->toBe(1)->and($planned->json('data.0.item_code'))->toBe('FG-MRP');

    $shortages = $this->getJson('/api/v1/manufacturing/mrp/shortages?grid_values=mfgItem.item_code')->assertOk();
    expect($shortages->json('data'))->toBe([['value' => 'FG-MRP', 'count' => 1]]);

    $runs = $this->getJson('/api/v1/manufacturing/mrp/runs?sort_by=run_number&grid_values=status')->assertOk();
    expect($runs->json('data.0.value'))->toBe($run->status)
        ->and($this->getJson('/api/v1/manufacturing/mrp/runs')->assertOk()->json('data.0.created_at'))->not->toBeNull();

    expect(MfgBom::query()->count())->toBe(0);
});
