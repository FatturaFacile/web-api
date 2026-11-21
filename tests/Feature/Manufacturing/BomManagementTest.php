<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Models\MfgItem;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
});

/**
 * Creates and releases a BOM through the API and returns its id.
 *
 * @param  list<array<string, mixed>>  $components
 * @param  array<string, mixed>  $overrides
 */
function bomReleased(object $test, MfgItem $item, array $components, array $overrides = []): string
{
    $id = (string) $test->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $item->id, 'name' => $item->item_code.' BOM', 'components' => $components, ...$overrides])->assertCreated()->json('data.id');
    $test->postJson("/api/v1/manufacturing/boms/{$id}/activate")->assertOk();

    return $id;
}

/**
 * @return array<string, mixed>
 */
function bomLine(MfgItem $item, string $quantity, array $extra = []): array
{
    return ['component_item_id' => $item->id, 'quantity_per' => $quantity, ...$extra];
}

test('circular references are refused at any depth, before anything is written', function (): void {
    $a = mfgItem($this, 'CY-A', 'finished_good');
    $b = mfgItem($this, 'CY-B', 'subassembly');
    $c = mfgItem($this, 'CY-C', 'subassembly');
    $d = mfgItem($this, 'CY-D', 'subassembly');
    $raw = mfgItem($this, 'CY-RAW', 'raw_material', '1.00');

    bomReleased($this, $d, [bomLine($raw, '1')]);
    bomReleased($this, $c, [bomLine($d, '1')]);
    bomReleased($this, $b, [bomLine($c, '1')]);
    bomReleased($this, $a, [bomLine($b, '1')]);

    // D made of A closes A > B > C > D > A.
    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $d->id, 'name' => 'Loop', 'bom_number' => 'BOM-LOOP', 'components' => [bomLine($a, '1')]])
        ->assertUnprocessable()->assertJsonValidationErrors(['components'])->assertJsonPath('errors.components.0', 'Circular BOM reference: CY-D > CY-A > CY-B > CY-C > CY-D.');
    expect(MfgBom::query()->where('bom_number', 'BOM-LOOP')->exists())->toBeFalse();

    // Directly and through a draft that is edited.
    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $a->id, 'name' => 'Self', 'bom_number' => 'BOM-SELF', 'components' => [bomLine($a, '1')]])->assertUnprocessable()->assertJsonValidationErrors(['components']);
    $draft = (string) $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $c->id, 'name' => 'C draft', 'bom_number' => 'BOM-C2', 'components' => [bomLine($raw, '1')]])->assertCreated()->json('data.id');
    $this->putJson("/api/v1/manufacturing/boms/{$draft}", ['components' => [bomLine($a, '1')]])->assertUnprocessable()->assertJsonValidationErrors(['components']);
});

test('fixed setup waste is added once while proportional scrap and yield compound across levels', function (): void {
    $fg = mfgItem($this, 'SC-FG', 'finished_good', '0', ['lead_time_days' => 5]);
    $sa = mfgItem($this, 'SC-SA', 'subassembly', '0', ['lead_time_days' => 3]);
    $rm = mfgItem($this, 'SC-RM', 'raw_material', '1.00', ['lead_time_days' => 2]);
    $rm2 = mfgItem($this, 'SC-RM2', 'raw_material', '1.00', ['lead_time_days' => 1]);

    bomReleased($this, $sa, [bomLine($rm, '4', ['fixed_scrap_quantity' => '1'])]);
    $bom = bomReleased($this, $fg, [
        bomLine($sa, '2', ['scrap_percentage' => '5']),
        bomLine($rm2, '10', ['scrap_percentage' => '10', 'fixed_scrap_quantity' => '2']),
    ]);

    $response = $this->getJson("/api/v1/manufacturing/boms/{$bom}/explosion?quantity=10")->assertOk();
    $lines = collect($response->json('lines'))->keyBy('component_item_code');

    // RM2: net 100, proportional waste 10, fixed waste 2 -> 112 (the fixed part does not scale with the quantity).
    expect($lines['SC-RM2']['net_quantity'])->toBe('100.0000')
        ->and($lines['SC-RM2']['variable_waste_quantity'])->toBe('10.0000')
        ->and($lines['SC-RM2']['fixed_waste_quantity'])->toBe('2.0000')
        ->and($lines['SC-RM2']['effective_quantity'])->toBe('112.0000')
        ->and($lines['SC-RM2']['scrap_quantity'])->toBe('12.0000');

    // SA: 2 per FG with 5% scrap -> 21; its own BOM is exploded for 21: 4 x 21 + 1 fixed = 85.
    expect($lines['SC-SA']['effective_quantity'])->toBe('21.0000')->and($lines['SC-SA']['level'])->toBe(1)
        ->and($lines['SC-RM']['effective_quantity'])->toBe('85.0000')->and($lines['SC-RM']['level'])->toBe(2);

    // The fixed waste does not grow with the quantity: the same BOM for 1 unit.
    $one = collect($this->getJson("/api/v1/manufacturing/boms/{$bom}/explosion?quantity=1")->json('lines'))->keyBy('component_item_code');
    expect($one['SC-RM2']['effective_quantity'])->toBe('13.0000');

    // Lead time offsets: RM sits under FG (5 days) and SA (3 days), then takes its own 2 days to procure.
    expect($lines['SC-RM']['lead_time_offset_days'])->toBe(8)->and($lines['SC-SA']['lead_time_offset_days'])->toBe(5)->and($response->json('total_lead_time_days'))->toBe(10);

    // First level only.
    $single = $this->getJson("/api/v1/manufacturing/boms/{$bom}/explosion?quantity=10&multi_level=0")->assertOk()->json('lines');
    expect(collect($single)->pluck('component_item_code')->all())->toBe(['SC-SA', 'SC-RM2']);
});

test('phantom assemblies are flattened into their components', function (): void {
    $fg = mfgItem($this, 'PH-FG', 'finished_good', '0', ['lead_time_days' => 2]);
    $ph = mfgItem($this, 'PH-PH', 'phantom', '0', ['lead_time_days' => 4]);
    $rm = mfgItem($this, 'PH-RM', 'raw_material', '1.00');
    $rm2 = mfgItem($this, 'PH-RM2', 'raw_material', '1.00');

    bomReleased($this, $ph, [bomLine($rm, '3', ['scrap_percentage' => '10']), bomLine($rm2, '1')]);
    $bom = bomReleased($this, $fg, [bomLine($ph, '2')]);

    $flat = collect($this->getJson("/api/v1/manufacturing/boms/{$bom}/explosion?quantity=5")->assertOk()->json('lines'));
    expect($flat->pluck('component_item_code')->all())->toBe(['PH-RM', 'PH-RM2'])
        ->and($flat->every(fn (array $line): bool => $line['via_phantom'] === true && $line['level'] === 1 && $line['lead_time_offset_days'] === 2))->toBeTrue();
    // 5 FG need 10 phantoms; each phantom 3 RM with 10% scrap -> 33.
    expect($flat->firstWhere('component_item_code', 'PH-RM')['effective_quantity'])->toBe('33.0000');

    // Without flattening the phantom is a node of its own, its components one level below.
    $tree = collect($this->getJson("/api/v1/manufacturing/boms/{$bom}/explosion?quantity=5&flatten_phantoms=0")->json('lines'));
    expect($tree->pluck('component_item_code')->all())->toBe(['PH-PH', 'PH-RM', 'PH-RM2'])->and($tree[0]['is_phantom'])->toBeTrue()->and($tree[1]['level'])->toBe(2);

    // A phantom flattened by a first-level explosion still shows what it consists of.
    $single = collect($this->getJson("/api/v1/manufacturing/boms/{$bom}/explosion?quantity=5&multi_level=0")->json('lines'));
    expect($single->pluck('component_item_code')->all())->toBe(['PH-RM', 'PH-RM2']);
});

test('a revision opens an engineering change with its diff, waits for approval and replaces the released revision', function (): void {
    $fg = mfgItem($this, 'EC-FG', 'finished_good');
    $rm1 = mfgItem($this, 'EC-RM1', 'raw_material', '1.00');
    $rm2 = mfgItem($this, 'EC-RM2', 'raw_material', '2.00');
    $rm3 = mfgItem($this, 'EC-RM3', 'raw_material', '3.00');
    $bom = bomReleased($this, $fg, [bomLine($rm1, '2'), bomLine($rm2, '1')]);
    $from = now()->addDay()->toDateString();

    $payload = ['source_bom_id' => $bom, 'reason' => 'Cheaper material', 'effective_from' => $from, 'components' => [
        ['action' => 'update', 'component_item_id' => $rm1->id, 'quantity_per' => '3', 'scrap_percentage' => '2'],
        ['action' => 'remove', 'component_item_id' => $rm2->id],
        ['action' => 'add', 'component_item_id' => $rm3->id, 'quantity_per' => '1'],
    ]];

    // Rejected: the proposal goes back to draft and the released revision is untouched.
    $first = $this->postJson('/api/v1/manufacturing/boms/revisions', $payload)->assertCreated()
        ->assertJsonPath('data.status', 'pending_eco_approval')->assertJsonPath('data.revision', 'B')
        ->assertJsonPath('engineering_change.status', 'submitted')
        ->assertJsonPath('engineering_change.diff_payload.summary', ['added' => 1, 'removed' => 1, 'modified' => 1, 'unchanged' => 0]);
    expect($first->json('engineering_change.diff_payload.modified.0.changes.quantity_per'))->toBe(['from' => '2.0000', 'to' => '3.0000']);

    mfgActAs($this, $this->production);
    $this->postJson('/api/v1/manufacturing/engineering-changes/'.$first->json('engineering_change.id').'/reject', ['reason' => 'Supplier not qualified'])->assertOk();
    $this->getJson('/api/v1/manufacturing/boms/'.$first->json('data.id'))->assertOk()->assertJsonPath('data.status', 'draft');
    $this->getJson("/api/v1/manufacturing/boms/{$bom}")->assertJsonPath('data.status', 'active');

    // Approved by someone else and implemented: the new revision is released and the old one ends the day before.
    mfgActAs($this, $this->owner);
    $second = $this->postJson('/api/v1/manufacturing/boms/revisions', [...$payload, 'new_revision' => 'C'])->assertCreated()->assertJsonPath('data.status', 'pending_eco_approval');
    $ecoId = (string) $second->json('engineering_change.id');
    $newBom = (string) $second->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$newBom}/activate")->assertUnprocessable();

    mfgActAs($this, $this->production);
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/implement")->assertOk()->assertJsonPath('data.status', 'implemented')->assertJsonPath('data.resulting_bom_id', $newBom);

    $this->getJson("/api/v1/manufacturing/boms/{$newBom}")->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.effective_from', $from)->assertJsonPath('data.engineering_change_order_id', $ecoId);
    $this->getJson("/api/v1/manufacturing/boms/{$bom}")->assertOk()->assertJsonPath('data.status', 'superseded')->assertJsonPath('data.effective_to', now()->toDateString());
    expect(MfgEngineeringChangeOrder::query()->count())->toBe(2);

    // The diff of two revisions can be asked for at any time.
    $this->getJson("/api/v1/manufacturing/boms/{$bom}/diff/{$newBom}")->assertOk()->assertJsonPath('summary.added', 1)->assertJsonPath('removed.0.component_item_code', 'EC-RM2');

    // Only a released revision is revised through a change.
    $this->postJson('/api/v1/manufacturing/boms/revisions', ['source_bom_id' => $first->json('data.id'), 'reason' => 'x', 'components' => []])->assertUnprocessable()->assertJsonValidationErrors(['source_bom_id']);
    $this->postJson('/api/v1/manufacturing/boms/revisions', ['source_bom_id' => (string) Str::uuid(), 'reason' => 'x'])->assertUnprocessable();
});

test('without a source BOM the same endpoint creates a draft', function (): void {
    $fg = mfgItem($this, 'CR-FG', 'finished_good');
    $rm = mfgItem($this, 'CR-RM', 'raw_material', '1.00');

    $this->postJson('/api/v1/manufacturing/boms/revisions', ['mfg_item_id' => $fg->id, 'name' => 'Brand new', 'bom_type' => 'process_formula', 'components' => [bomLine($rm, '2', ['issue_method' => 'backflush_order_close', 'fixed_scrap_quantity' => '0.5'])]])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.bom_type', 'process_formula')->assertJsonPath('engineering_change', null)
        ->assertJsonPath('data.components.0.issue_method', 'backflush_order_close')->assertJsonPath('data.components.0.fixed_scrap_quantity', '0.5000');
    $this->postJson('/api/v1/manufacturing/boms/revisions', ['mfg_item_id' => $fg->id, 'name' => 'Bad', 'components' => []])->assertUnprocessable()->assertJsonValidationErrors(['components']);
});

test('substitutes are kept in priority order and must share the unit of the component', function (): void {
    $fg = mfgItem($this, 'SB-FG', 'finished_good');
    $rm = mfgItem($this, 'SB-RM', 'raw_material', '1.00');
    $alt1 = mfgItem($this, 'SB-ALT1', 'raw_material', '1.00');
    $alt2 = mfgItem($this, 'SB-ALT2', 'raw_material', '1.00');

    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Subs', 'components' => [bomLine($rm, '1', ['substitute_item_ids' => [$alt2->id, $alt1->id]])]])
        ->assertCreated()->assertJsonPath('data.components.0.substitute_item_ids', [$alt2->id, $alt1->id]);
    $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Self sub', 'bom_number' => 'BOM-SB2', 'components' => [bomLine($rm, '1', ['substitute_item_ids' => [$rm->id]])]])
        ->assertUnprocessable()->assertJsonValidationErrors(['components']);
});

test('where-used lists every parent at any depth with cumulative coefficients', function (): void {
    $fg = mfgItem($this, 'WU-FG', 'finished_good');
    $sa = mfgItem($this, 'WU-SA', 'subassembly');
    $rm = mfgItem($this, 'WU-RM', 'raw_material', '1.00');
    $other = mfgItem($this, 'WU-OTHER', 'finished_good');

    bomReleased($this, $sa, [bomLine($rm, '2', ['scrap_percentage' => '10'])]);
    bomReleased($this, $fg, [bomLine($sa, '3')]);
    bomReleased($this, $other, [bomLine($rm, '1')]);

    $response = $this->getJson("/api/v1/manufacturing/items/{$rm->id}/implosion")->assertOk();
    $parents = collect($response->json('parents'))->keyBy('parent_item_code');
    expect($parents->keys()->sort()->values()->all())->toBe(['WU-FG', 'WU-OTHER', 'WU-SA'])
        ->and($parents['WU-SA']['cumulative_coefficient'])->toBe('2.200000')->and($parents['WU-SA']['is_top_level'])->toBeFalse()
        ->and($parents['WU-FG']['cumulative_coefficient'])->toBe('6.600000')->and($parents['WU-FG']['level'])->toBe(2)->and($parents['WU-FG']['path'])->toBe(['WU-RM', 'WU-SA', 'WU-FG'])
        ->and($parents['WU-FG']['is_top_level'])->toBeTrue()
        ->and(collect($response->json('top_level'))->pluck('item_code')->sort()->values()->all())->toBe(['WU-FG', 'WU-OTHER']);

    $this->getJson('/api/v1/manufacturing/items/'.Str::uuid().'/implosion')->assertNotFound();
});

test('a component is replaced across the released BOMs with one engineering change each', function (): void {
    $old = mfgItem($this, 'MR-OLD', 'raw_material', '1.00');
    $new = mfgItem($this, 'MR-NEW', 'raw_material', '1.00');
    $keep = mfgItem($this, 'MR-KEEP', 'raw_material', '1.00');
    $fg1 = mfgItem($this, 'MR-FG1', 'finished_good');
    $fg2 = mfgItem($this, 'MR-FG2', 'finished_good');
    $fg3 = mfgItem($this, 'MR-FG3', 'finished_good');

    $bom1 = bomReleased($this, $fg1, [bomLine($old, '4', ['scrap_percentage' => '3', 'fixed_scrap_quantity' => '1']), bomLine($keep, '1')]);
    bomReleased($this, $fg2, [bomLine($old, '2')]);
    bomReleased($this, $fg3, [bomLine($old, '1'), bomLine($new, '1')]);
    $payload = ['obsolete_item_id' => $old->id, 'substitute_item_id' => $new->id, 'reason' => 'Supplier insolvent', 'effective_from' => now()->addDay()->toDateString()];

    $dry = $this->postJson('/api/v1/manufacturing/boms/mass-replace', [...$payload, 'dry_run' => true])->assertOk();
    expect($dry->json('replaced'))->toHaveCount(2)->and($dry->json('skipped.0.reason'))->toBe('already_contains_substitute')->and(MfgEngineeringChangeOrder::query()->count())->toBe(0);

    $done = $this->postJson('/api/v1/manufacturing/boms/mass-replace', $payload)->assertOk();
    expect($done->json('replaced'))->toHaveCount(2)->and(MfgEngineeringChangeOrder::query()->where('status', 'submitted')->count())->toBe(2)
        ->and(MfgBom::query()->where('status', 'pending_eco_approval')->count())->toBe(2);

    $proposal = collect($done->json('replaced'))->firstWhere('bom_id', $bom1);
    $line = collect($this->getJson('/api/v1/manufacturing/boms/'.$proposal['new_bom_id'])->assertOk()->json('data.components'))->keyBy('component_item_code');
    expect($line->keys()->sort()->values()->all())->toBe(['MR-KEEP', 'MR-NEW'])
        ->and($line['MR-NEW']['quantity_per'])->toBe('4.0000')->and($line['MR-NEW']['scrap_percentage'])->toBe('3.00')->and($line['MR-NEW']['fixed_scrap_quantity'])->toBe('1.0000');
    // The released revisions stay in force until the changes are approved.
    $this->getJson("/api/v1/manufacturing/boms/{$bom1}")->assertJsonPath('data.status', 'active');

    // Releasing a BOM needs a manager: a plain member cannot replace.
    mfgActAs($this, $this->member);
    $this->postJson('/api/v1/manufacturing/boms/mass-replace', [...$payload, 'obsolete_item_id' => $new->id, 'substitute_item_id' => $keep->id])->assertForbidden();
    $this->postJson('/api/v1/manufacturing/boms/mass-replace', [...$payload, 'substitute_item_id' => $old->id])->assertUnprocessable()->assertJsonValidationErrors(['substitute_item_id']);
});

test('a replacement that would close a loop stops the whole operation', function (): void {
    $old = mfgItem($this, 'ML-OLD', 'raw_material', '1.00');
    $sa = mfgItem($this, 'ML-SA', 'subassembly');
    $fg = mfgItem($this, 'ML-FG', 'finished_good');
    $other = mfgItem($this, 'ML-OTHER', 'finished_good');

    bomReleased($this, $other, [bomLine($old, '1')]);
    bomReleased($this, $sa, [bomLine($old, '1')]);
    bomReleased($this, $fg, [bomLine($sa, '1')]);

    // FG as the substitute of OLD would put FG inside SA, which FG uses.
    $this->postJson('/api/v1/manufacturing/boms/mass-replace', ['obsolete_item_id' => $old->id, 'substitute_item_id' => $fg->id, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['components']);
    expect(MfgEngineeringChangeOrder::query()->count())->toBe(0)->and(MfgBom::query()->where('status', 'pending_eco_approval')->count())->toBe(0);
});

test('the EUR.1 test compares the non-originating material value of the whole BOM with the ex-works price', function (): void {
    $product = mfgItem($this, 'OR-P', 'finished_good', '0', ['exw_price' => '100']);
    $sa = mfgItem($this, 'OR-SA', 'subassembly', '45.00');
    $m1 = mfgItem($this, 'OR-M1', 'raw_material', '30.00');
    $m2 = mfgItem($this, 'OR-M2', 'raw_material', '20.00', ['origin_status' => 'originating']);
    $m4 = mfgItem($this, 'OR-M4', 'raw_material', '10.00');
    $m5 = mfgItem($this, 'OR-M5', 'raw_material', '35.00', ['origin_status' => 'originating']);

    bomReleased($this, $sa, [bomLine($m4, '1'), bomLine($m5, '1')]);
    $bom = bomReleased($this, $product, [bomLine($m1, '1'), bomLine($m2, '1'), bomLine($sa, '1')]);

    // Non-originating: M1 30 + M4 10 = 40 of 100 -> 40%, eligible at the default 50%.
    $result = $this->getJson("/api/v1/manufacturing/boms/{$bom}/preferential-origin")->assertOk()
        ->assertJsonPath('exw_price', '100.0000')->assertJsonPath('non_originating_value', '40.0000')->assertJsonPath('originating_value', '55.0000')
        ->assertJsonPath('non_originating_percentage', '40.00')->assertJsonPath('threshold_percentage', '50.00')->assertJsonPath('eligible', true);
    expect(collect($result->json('lines'))->pluck('item_code')->sort()->values()->all())->toBe(['OR-M1', 'OR-M2', 'OR-M4', 'OR-M5']);

    // A stricter threshold is not met: 40% > 35%.
    $this->getJson("/api/v1/manufacturing/boms/{$bom}/preferential-origin?threshold_percentage=35")->assertOk()->assertJsonPath('eligible', false);

    // The threshold itself is still eligible: exactly 40%.
    $this->getJson("/api/v1/manufacturing/boms/{$bom}/preferential-origin?threshold_percentage=40")->assertOk()->assertJsonPath('eligible', true);

    // An originating sub-assembly counts as one originating material, its own materials are not tested.
    $this->putJson("/api/v1/manufacturing/items/{$sa->id}", ['origin_status' => 'originating'])->assertOk()->assertJsonPath('data.origin_status', 'originating');
    $this->getJson("/api/v1/manufacturing/boms/{$bom}/preferential-origin")->assertOk()->assertJsonPath('non_originating_value', '30.0000')->assertJsonPath('non_originating_percentage', '30.00');

    // Above the threshold: raise the non-originating material cost.
    $this->putJson("/api/v1/manufacturing/items/{$m1->id}", ['standard_cost' => '60.00'])->assertOk();
    $this->getJson("/api/v1/manufacturing/boms/{$bom}/preferential-origin")->assertOk()->assertJsonPath('non_originating_percentage', '60.00')->assertJsonPath('eligible', false);

    // Without an ex-works price there is nothing to compare with.
    $noPrice = mfgItem($this, 'OR-NP', 'finished_good');
    $noPriceBom = bomReleased($this, $noPrice, [bomLine($m1, '1')]);
    $this->getJson("/api/v1/manufacturing/boms/{$noPriceBom}/preferential-origin")->assertUnprocessable()->assertJsonValidationErrors(['exw_price']);
});

test('co-products and by-products carry shares of the cost that never exceed 100 percent', function (): void {
    $fg = mfgItem($this, 'CP-FG', 'finished_good');
    $co = mfgItem($this, 'CP-CO', 'finished_good');
    $by = mfgItem($this, 'CP-BY', 'raw_material');
    $rm = mfgItem($this, 'CP-RM', 'raw_material', '1.00');
    $draft = (string) $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Split', 'components' => [bomLine($rm, '1')]])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/manufacturing/boms/{$draft}/co-products", ['co_products' => [
        ['product_item_id' => $co->id, 'output_ratio_qty' => '0.5', 'cost_allocation_percentage' => '60'],
        ['product_item_id' => $by->id, 'product_type' => 'by_product', 'output_ratio_qty' => '0.1', 'cost_allocation_percentage' => '40.01'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['co_products']);
    $this->putJson("/api/v1/manufacturing/boms/{$draft}/co-products", ['co_products' => [['product_item_id' => $fg->id, 'output_ratio_qty' => '1', 'cost_allocation_percentage' => '10']]])->assertUnprocessable()->assertJsonValidationErrors(['co_products']);

    $this->putJson("/api/v1/manufacturing/boms/{$draft}/co-products", ['co_products' => [
        ['product_item_id' => $co->id, 'output_ratio_qty' => '0.5', 'cost_allocation_percentage' => '25.50'],
        ['product_item_id' => $by->id, 'product_type' => 'by_product', 'output_ratio_qty' => '0.1', 'cost_allocation_percentage' => '4.50'],
    ]])->assertOk()->assertJsonPath('allocation.primary_percentage', '70.00')->assertJsonPath('allocation.total_percentage', '100.00')->assertJsonCount(2, 'data.co_products');
    $this->getJson("/api/v1/manufacturing/boms/{$draft}/cost-allocation")->assertOk()->assertJsonPath('primary_percentage', '70.00');

    // A revision keeps the shares.
    $this->postJson("/api/v1/manufacturing/boms/{$draft}/activate")->assertOk();
    $this->putJson("/api/v1/manufacturing/boms/{$draft}/co-products", ['co_products' => []])->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $revision = (string) $this->postJson("/api/v1/manufacturing/boms/{$draft}/revise", ['reason' => 'Copy', 'effective_from' => now()->addDay()->toDateString()])->assertCreated()->json('data.id');
    $this->getJson("/api/v1/manufacturing/boms/{$revision}/cost-allocation")->assertOk()->assertJsonPath('primary_percentage', '70.00')->assertJsonCount(2, 'co_products');
});

test('the import dry run reports every row and the commit needs all of them valid', function (): void {
    $product = mfgItem($this, 'IM-P', 'finished_good');
    $a = mfgItem($this, 'IM-A', 'raw_material', '1.00');
    $b = mfgItem($this, 'IM-B', 'raw_material', '1.00');
    $up = mfgItem($this, 'IM-UP', 'subassembly');
    bomReleased($this, $up, [bomLine($product, '1')]);

    $rows = [
        ['item_code' => 'IM-A', 'quantity_per' => '2', 'uom' => 'PCS'],
        ['item_code' => 'NOPE', 'quantity_per' => '1'],
        ['item_code' => 'IM-B', 'quantity_per' => '0'],
        ['item_code' => 'IM-A', 'quantity_per' => '1'],
        ['item_code' => 'IM-B', 'quantity_per' => '1', 'uom' => 'KG'],
        ['item_code' => 'IM-P', 'quantity_per' => '1'],
        ['item_code' => 'IM-UP', 'quantity_per' => '1'],
    ];
    $report = $this->postJson('/api/v1/manufacturing/boms/import', ['product_code' => 'IM-P', 'dry_run' => true, 'rows' => $rows])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('summary', ['total' => 7, 'valid' => 1, 'invalid' => 6]);
    expect(collect($report->json('rows'))->pluck('errors')->all())->toBe([[], ['unknown_item'], ['invalid_quantity'], ['duplicate_item'], ['uom_mismatch', 'duplicate_item'], ['self_reference'], ['circular_reference']])
        ->and(MfgBom::query()->where('mfg_item_id', $product->id)->exists())->toBeFalse();

    $this->postJson('/api/v1/manufacturing/boms/import', ['product_code' => 'IM-P', 'name' => 'Imported', 'dry_run' => false, 'rows' => $rows])->assertUnprocessable()->assertJsonValidationErrors(['rows']);
    $this->postJson('/api/v1/manufacturing/boms/import', ['product_code' => 'NOPE', 'dry_run' => true, 'rows' => [['item_code' => 'IM-A', 'quantity_per' => '1']]])->assertOk()->assertJsonPath('valid', false)->assertJsonPath('product_item_id', null);

    $valid = [['item_code' => 'IM-A', 'quantity_per' => '2', 'scrap_percentage' => '5'], ['item_code' => 'IM-B', 'quantity_per' => '1.5', 'fixed_scrap_quantity' => '1']];
    $this->postJson('/api/v1/manufacturing/boms/import', ['product_code' => 'IM-P', 'dry_run' => true, 'rows' => $valid])->assertOk()->assertJsonPath('valid', true);
    $this->postJson('/api/v1/manufacturing/boms/import', ['product_code' => 'IM-P', 'name' => 'Imported', 'dry_run' => false, 'rows' => $valid])->assertCreated()
        ->assertJsonPath('data.status', 'draft')->assertJsonCount(2, 'data.components')->assertJsonPath('data.components.1.fixed_scrap_quantity', '1.0000');
});

test('BOM analyses only see the active company', function (): void {
    $this->getJson('/api/v1/manufacturing/boms/'.Str::uuid().'/explosion')->assertNotFound();
    $this->getJson('/api/v1/manufacturing/boms/'.Str::uuid().'/preferential-origin')->assertNotFound();

    $foreign = mfgItem($this, 'TN-FOREIGN', 'raw_material', '1.00', [], 'none', $this->otherTenant);
    $fg = mfgItem($this, 'TN-FG', 'finished_good');
    $this->postJson('/api/v1/manufacturing/boms/revisions', ['mfg_item_id' => $fg->id, 'name' => 'Leak', 'components' => [bomLine($foreign, '1')]])->assertUnprocessable()->assertJsonValidationErrors(['components.0.component_item_id']);

    mfgActAs($this, $this->reader);
    $this->postJson('/api/v1/manufacturing/boms/mass-replace', ['obsolete_item_id' => $fg->id, 'substitute_item_id' => $fg->id, 'reason' => 'x'])->assertForbidden();
});
