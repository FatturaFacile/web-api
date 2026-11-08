<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
});

/**
 * Creates and activates a BOM through the API.
 *
 * @param  list<array<string, mixed>>  $components
 * @param  array<string, mixed>  $overrides
 */
function mfgActiveBom(object $test, MfgItem $item, array $components, array $overrides = []): string
{
    $id = (string) $test->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $item->id, 'name' => $item->item_code.' BOM', 'components' => $components, ...$overrides])->assertCreated()->json('data.id');
    $test->postJson("/api/v1/manufacturing/boms/{$id}/activate")->assertOk();

    return $id;
}

test('items are created, listed, filtered, shown and updated through the API', function (): void {
    $stock = mfgItem($this, 'RM-BASE', 'raw_material', '3.00');
    $this->postJson('/api/v1/manufacturing/items', ['item_code' => 'FG-NEW', 'name' => 'New pump', 'item_type' => 'finished_good', 'stock_item_id' => $stock->stock_item_id, 'safety_stock' => '5', 'min_lot_size' => '10', 'lot_size_multiple' => '5'])
        ->assertCreated()->assertJsonPath('data.procurement_type', 'make')->assertJsonPath('data.safety_stock', '5.0000');
    $this->postJson('/api/v1/manufacturing/items', ['item_code' => 'FG-NEW', 'name' => 'Again', 'item_type' => 'gadget', 'stock_item_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors(['item_code', 'item_type', 'stock_item_id']);

    $id = $this->getJson('/api/v1/manufacturing/items?search=fg-new')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
    $this->getJson('/api/v1/manufacturing/items?item_type=raw_material')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/manufacturing/items/{$id}")->assertOk()->assertJsonPath('data.item_code', 'FG-NEW');
    $this->putJson("/api/v1/manufacturing/items/{$id}", ['name' => 'Renamed', 'procurement_type' => 'subcontract', 'lead_time_days' => 9])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.procurement_type', 'subcontract')->assertJsonPath('data.lead_time_days', 9);
    $this->putJson("/api/v1/manufacturing/items/{$id}", ['min_lot_size' => '-1'])->assertUnprocessable()->assertJsonValidationErrors(['min_lot_size']);
});

test('BOM drafts are edited, released with effective dates, superseded and refused when recursive', function (): void {
    $fg = mfgItem($this, 'FG-A', 'finished_good');
    $rm1 = mfgItem($this, 'RM-1', 'raw_material', '1.00');
    $rm2 = mfgItem($this, 'RM-2', 'raw_material', '2.00');

    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Self', 'components' => [['component_item_id' => $fg->id, 'quantity_per' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['components']);
    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Bad', 'components' => [['component_item_id' => $rm1->id, 'quantity_per' => '0', 'yield_percentage' => '0']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['components.0.quantity_per', 'components.0.yield_percentage']);

    $draft = (string) $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'A', 'components' => [['component_item_id' => $rm1->id, 'quantity_per' => '1']]])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision', 'A')->json('data.id');
    $this->putJson("/api/v1/manufacturing/boms/{$draft}", ['name' => 'A edited', 'components' => [['component_item_id' => $rm1->id, 'quantity_per' => '2'], ['component_item_id' => $rm2->id, 'quantity_per' => '1']]])
        ->assertOk()->assertJsonPath('data.name', 'A edited')->assertJsonCount(2, 'data.components');

    mfgActAs($this, $this->member);
    $this->postJson("/api/v1/manufacturing/boms/{$draft}/activate")->assertForbidden();
    mfgActAs($this, $this->production);
    $this->postJson("/api/v1/manufacturing/boms/{$draft}/activate")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.effective_from', now()->toDateString());
    $this->putJson("/api/v1/manufacturing/boms/{$draft}", ['name' => 'Too late'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    expect($fg->refresh()->default_bom_id)->toBe($draft);

    // Revision B from next week: A ends the day before, both stay effective in their own range.
    $from = now()->addWeek()->toDateString();
    $revB = (string) $this->postJson("/api/v1/manufacturing/boms/{$draft}/revise", ['reason' => 'Cheaper seal', 'effective_from' => $from, 'components' => [['action' => 'remove', 'component_item_id' => $rm2->id], ['action' => 'update', 'component_item_id' => $rm1->id, 'quantity_per' => '3']]])
        ->assertCreated()->assertJsonPath('data.revision', 'B')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.supersedes_bom_id', $draft)->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$revB}/activate")->assertOk()->assertJsonPath('data.effective_from', $from);
    $this->getJson("/api/v1/manufacturing/boms/{$draft}")->assertOk()->assertJsonPath('data.status', 'superseded')->assertJsonPath('data.effective_to', now()->addDays(6)->toDateString());

    $this->getJson("/api/v1/manufacturing/boms/{$draft}/explode?quantity=2")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/manufacturing/boms?mfg_item_id='.$fg->id)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/manufacturing/boms?status=active')->assertOk()->assertJsonCount(1, 'data');

    // A future revision cannot overlap an earlier start.
    $revC = (string) $this->postJson("/api/v1/manufacturing/boms/{$revB}/revise", ['reason' => 'Overlap', 'effective_from' => now()->addDays(3)->toDateString()])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$revC}/activate")->assertUnprocessable()->assertJsonValidationErrors(['effective_from']);
    $this->postJson("/api/v1/manufacturing/boms/{$revB}/revise", ['reason' => 'Duplicate', 'new_revision' => 'B'])->assertUnprocessable()->assertJsonValidationErrors(['new_revision']);

    // A BOM of RM-1 made of FG-A would close a loop.
    $loop = (string) $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $rm1->id, 'name' => 'Loop', 'components' => [['component_item_id' => $fg->id, 'quantity_per' => '1']]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$loop}/activate")->assertUnprocessable()->assertJsonValidationErrors(['components']);

    $this->getJson("/api/v1/manufacturing/items/{$rm1->id}/where-used")->assertOk()->assertJsonCount(3, 'data');
});

test('a multi-level BOM explodes through phantoms with scrap and yield and rolls up the standard cost', function (): void {
    $fg = mfgItem($this, 'FG-M', 'finished_good');
    $sub = mfgItem($this, 'SA-M', 'subassembly');
    $phantom = mfgItem($this, 'PH-M', 'phantom');
    $rm = mfgItem($this, 'RM-M', 'raw_material', '4.00');
    $wc = mfgWorkCenter($this, 'WC-M', '48.00', '24.00');

    mfgActiveBom($this, $phantom, [['component_item_id' => $rm->id, 'quantity_per' => '1']]);
    mfgActiveBom($this, $sub, [['component_item_id' => $rm->id, 'quantity_per' => '2', 'scrap_percentage' => '10']], ['base_quantity' => '2']);
    $bomId = mfgActiveBom($this, $fg, [['component_item_id' => $sub->id, 'quantity_per' => '1'], ['component_item_id' => $phantom->id, 'quantity_per' => '2', 'yield_percentage' => '80']]);
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $sub->id, 'name' => 'Sub', 'operations' => [['operation_code' => 'S10', 'name' => 'Weld', 'work_center_id' => $wc->id, 'setup_time_minutes' => '0', 'run_time_minutes_per_unit' => '15']]])->assertCreated();

    $rows = collect($this->getJson("/api/v1/manufacturing/boms/{$bomId}/explode?quantity=10")->assertOk()->json('data'));
    // Sub: 10; its raw material 2 per 2 units * 10 * 1.10 scrap = 11; phantom 2 * 10 / 0.8 = 25, its raw material 25.
    expect($rows->where('component_item_id', $sub->id)->first()['effective_quantity'])->toBe('10.0000')
        ->and($rows->where('level', 2)->pluck('effective_quantity')->sort()->values()->all())->toBe(['11.0000', '25.0000'])
        ->and($rows->firstWhere('component_item_id', $phantom->id)['is_phantom'])->toBeTrue();

    // Sub: material 2 * 1.10 * 4 / 2 = 4.40, labour 15 min at 48/h = 12, overhead 15 min at 24/h = 6 => 22.40.
    $rollup = $this->postJson("/api/v1/manufacturing/items/{$fg->id}/cost-rollup")->assertOk();
    expect($rollup->json('data.material'))->toBe('32.4000')
        ->and($rollup->json('data.total'))->toBe('32.4000')
        ->and($sub->refresh()->standard_cost)->toBe('22.4000')
        ->and($sub->standard_labor_cost)->toBe('12.0000');
});

test('routings are created with work centre rates, listed and shown', function (): void {
    $fg = mfgItem($this, 'FG-R', 'finished_good');
    $wc = mfgWorkCenter($this, 'WC-R', '50.00', '20.00');
    $foreignWc = mfgWorkCenter($this, 'WC-X', '1', '1', $this->otherTenant);

    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [['operation_code' => 'X', 'name' => 'X', 'work_center_id' => $foreignWc->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['operations.0.work_center_id']);
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R'])->assertUnprocessable()->assertJsonValidationErrors(['operations']);

    $id = $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'R', 'operations' => [
        ['operation_code' => 'OP10', 'name' => 'Cut', 'work_center_id' => $wc->id, 'setup_time_minutes' => '10', 'run_time_minutes_per_unit' => '2', 'labor_time_minutes_per_unit' => '1'],
        ['operation_code' => 'OP20', 'name' => 'Plate', 'work_center_id' => $wc->id, 'is_subcontracted' => true, 'subcontract_unit_cost' => '3.50'],
    ]])->assertCreated()->assertJsonCount(2, 'data.operations')->assertJsonPath('data.operations.1.is_subcontracted', true)->json('data.id');

    $this->getJson('/api/v1/manufacturing/routings?mfg_item_id='.$fg->id)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/manufacturing/routings/{$id}")->assertOk()->assertJsonPath('data.operations.0.labor_time_minutes_per_unit', '1.00');
    expect($fg->refresh()->default_routing_id)->toBe($id);
    $this->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'Again', 'operations' => [['operation_code' => 'OP10', 'name' => 'Cut', 'work_center_id' => $wc->id]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['revision']);
});

test('engineering changes are submitted, decided by another manager and implemented as a new effective revision', function (): void {
    $fg = mfgItem($this, 'FG-E', 'finished_good');
    $rm1 = mfgItem($this, 'RM-E1', 'raw_material', '1.00');
    $rm2 = mfgItem($this, 'RM-E2', 'raw_material', '1.00');
    $bomId = mfgActiveBom($this, $fg, [['component_item_id' => $rm1->id, 'quantity_per' => '1']]);

    mfgActAs($this, $this->production);
    $this->postJson('/api/v1/manufacturing/engineering-changes', ['bom_id' => $bomId, 'title' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'components']);
    $ecoId = $this->postJson('/api/v1/manufacturing/engineering-changes', [
        'bom_id' => $bomId,
        'title' => 'Substitute RM-E1',
        'reason' => 'Supplier discontinued',
        'effective_date' => now()->addDays(10)->toDateString(),
        'components' => [['action' => 'remove', 'component_item_id' => $rm1->id], ['action' => 'add', 'component_item_id' => $rm2->id, 'quantity_per' => '1.5']],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/approve")->assertForbidden();
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    // The requester cannot approve, a member cannot decide.
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/approve")->assertForbidden();
    mfgActAs($this, $this->member);
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/reject", ['reason' => 'No'])->assertForbidden();

    mfgActAs($this, $this->owner);
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/implement")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/approve")->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by', $this->owner->id);
    $implemented = $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/implement")->assertOk()->assertJsonPath('data.status', 'implemented');

    $newBom = MfgBom::query()->findOrFail($implemented->json('data.resulting_bom_id'));
    expect($newBom->revision)->toBe('B')
        ->and($newBom->status)->toBe('active')
        ->and($newBom->engineering_change_order_id)->toBe($ecoId)
        ->and($newBom->effective_from->toDateString())->toBe(now()->addDays(10)->toDateString())
        ->and($newBom->components()->pluck('component_item_id')->all())->toBe([$rm2->id])
        ->and(MfgBom::query()->findOrFail($bomId)->effective_to?->toDateString())->toBe(now()->addDays(9)->toDateString());

    $this->getJson('/api/v1/manufacturing/engineering-changes?status=implemented')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/manufacturing/engineering-changes/{$ecoId}")->assertOk()->assertJsonPath('data.resulting_bom_id', $newBom->id);

    $second = $this->postJson('/api/v1/manufacturing/engineering-changes', ['bom_id' => $newBom->id, 'title' => 'More', 'reason' => 'Test', 'components' => [['action' => 'update', 'component_item_id' => $rm2->id, 'quantity_per' => '2']]])->json('data.id');
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$second}/submit")->assertOk();
    mfgActAs($this, $this->production);
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$second}/reject", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$second}/reject", ['reason' => 'Not needed'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Not needed');
});

test('work centres keep a weekly calendar with exceptions and report capacity against the load of released orders', function (): void {
    $this->postJson('/api/v1/manufacturing/work-centers', ['name' => 'X', 'efficiency_rate' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['work_center_code', 'efficiency_rate']);
    $id = $this->postJson('/api/v1/manufacturing/work-centers', ['work_center_code' => 'WC-CAP', 'name' => 'Lathe', 'capacity_hours_per_day' => '8', 'efficiency_rate' => '90', 'availability_rate' => '100', 'hourly_cost_rate' => '40', 'hourly_overhead_rate' => '20'])
        ->assertCreated()->assertJsonPath('data.hourly_cost_rate', '40.0000')->json('data.id');
    $this->putJson("/api/v1/manufacturing/work-centers/{$id}", ['hourly_overhead_rate' => '25'])->assertOk()->assertJsonPath('data.hourly_overhead_rate', '25.0000');
    $this->getJson('/api/v1/manufacturing/work-centers')->assertOk()->assertJsonCount(1, 'data');

    $monday = now()->next('Monday')->toDateString();
    $this->putJson("/api/v1/manufacturing/work-centers/{$id}/calendar", ['days' => [['weekday' => 1, 'available_hours' => '10'], ['weekday' => 1, 'available_hours' => '2']]])->assertUnprocessable()->assertJsonValidationErrors(['days.0.weekday']);
    $this->putJson("/api/v1/manufacturing/work-centers/{$id}/calendar", ['days' => [['weekday' => 1, 'available_hours' => '10'], ['weekday' => 2, 'available_hours' => '10']]])
        ->assertOk()->assertJsonCount(2, 'data.calendar');
    $this->postJson("/api/v1/manufacturing/work-centers/{$id}/calendar-exceptions", ['calendar_date' => now()->next('Monday')->addDay()->toDateString(), 'available_hours' => '0', 'reason' => 'Maintenance'])
        ->assertCreated()->assertJsonPath('data.available_hours', '0.00')->assertJsonPath('data.is_exception', true);

    $days = collect($this->getJson("/api/v1/manufacturing/work-centers/{$id}/capacity?from={$monday}&to=".now()->next('Monday')->addDays(2)->toDateString())->assertOk()->json('data'));
    expect($days->pluck('available_hours')->all())->toBe(['9.00', '0.00', '0.00'])
        ->and($days[0]['utilization_percent'])->toBe('0.00')
        ->and($days[1]['utilization_percent'])->toBeNull();

    $this->getJson("/api/v1/manufacturing/work-centers/{$id}/capacity?from={$monday}&to=".now()->subYear()->toDateString())->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->getJson("/api/v1/manufacturing/work-centers/{$id}")->assertOk()->assertJsonPath('data.work_center_code', 'WC-CAP');
});
