<?php

declare(strict_types=1);

use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\MrpPlanning\Domain\Contracts\MrpSnapshotLoaderInterface;
use Modules\MrpPlanning\Domain\ValueObjects\BomLine;
use Modules\MrpPlanning\Domain\ValueObjects\ItemPlanningData;
use Modules\MrpPlanning\Domain\ValueObjects\MrpSnapshot;
use Modules\MrpPlanning\Domain\ValueObjects\RunParameters;
use Modules\MrpPlanning\Infrastructure\Models\MrpActionProposal;
use Modules\MrpPlanning\Infrastructure\Models\MrpItemParameter;
use Modules\MrpPlanning\Infrastructure\Models\MrpRunExecution;
use Modules\MrpPlanning\Infrastructure\Models\MrpScheduleBlock;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterAlternative;
use Modules\MrpPlanning\Infrastructure\Models\MrpWorkCenterCapacityBucket;
use Modules\Procurement\Models\PurchaseRequisition;

require_once __DIR__.'/../Manufacturing/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->contact = Contact::factory()->create(['tenant_id' => $this->tenant->id]);
    // A Friday in four weeks: a working day for the work centres whatever day the suite runs on.
    $this->due = CarbonImmutable::now()->startOfWeek()->addDays(4)->addWeeks(4);

    $this->rm = mrpFeatureItem($this, 'RM-1', 'raw_material', ['lead_time_days' => 4]);
    $this->sub = mrpFeatureItem($this, 'SUB-1', 'subassembly', ['lead_time_days' => 2, 'procurement_type' => 'make']);
    $this->fg = mrpFeatureItem($this, 'FG-1', 'finished_good', ['lead_time_days' => 3, 'procurement_type' => 'make']);
    mrpFeatureBom($this, $this->fg, $this->sub, '2');
    mrpFeatureBom($this, $this->sub, $this->rm, '3');
    mrpFeatureDemand($this, $this->fg, '20', $this->due);
});

/**
 * An item with a catalog product so that sales and purchase documents can refer to it.
 *
 * @param  array<string, mixed>  $attributes
 */
function mrpFeatureItem(object $test, string $code, string $type, array $attributes = []): MfgItem
{
    $productId = (string) Str::uuid7();
    DB::table('products')->insert(['id' => $productId, 'tenant_id' => $test->tenant->id, 'sku' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]);

    return mfgItem($test, $code, $type, '2.50', ['product_id' => $productId, ...$attributes]);
}

function mrpFeatureBom(object $test, MfgItem $parent, MfgItem $component, string $quantityPer): void
{
    $id = $test->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $parent->id, 'name' => $parent->item_code, 'components' => [['component_item_id' => $component->id, 'quantity_per' => $quantityPer]]])->assertCreated()->json('data.id');
    $test->postJson("/api/v1/manufacturing/boms/{$id}/activate")->assertOk();
}

function mrpFeatureDemand(object $test, MfgItem $item, string $quantity, CarbonImmutable $due): void
{
    $orderId = (string) Str::uuid7();
    DB::table('sales_orders')->insert(['id' => $orderId, 'tenant_id' => $test->tenant->id, 'contact_id' => $test->contact->id, 'order_number' => 'SO-'.Str::random(6), 'date' => now()->toDateString(), 'due_date' => $due->toDateString(), 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_order_items')->insert(['id' => (string) Str::uuid7(), 'sales_order_id' => $orderId, 'product_id' => $item->product_id, 'description' => 'Line', 'quantity' => $quantity, 'quantity_delivered' => 0, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * @param  array<string, mixed>  $parameters
 */
function mrpFeatureRun(object $test, array $parameters = []): MrpRunExecution
{
    $id = $test->postJson('/api/v1/mrp/runs', ['planning_horizon_days' => 120, ...$parameters])->assertCreated()->json('data.id');

    return MrpRunExecution::query()->findOrFail($id);
}

function mrpFeatureRouting(object $test, MfgItem $item, MfgWorkCenter $workCenter, string $setup, string $run): void
{
    $test->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $item->id, 'name' => $item->item_code, 'operations' => [['operation_code' => 'OP10', 'name' => 'Work', 'work_center_id' => $workCenter->id, 'setup_time_minutes' => $setup, 'run_time_minutes_per_unit' => $run]]])->assertCreated();
}

// ---------------------------------------------------------------- run, proposals, pegging

it('runs MRP over the multi-level BOM and stores proposals, pegging and the run KPIs', function (): void {
    $run = mrpFeatureRun($this, ['run_mode' => 'RegenerativeFull', 'capacity_scheduling_mode' => 'InfiniteCapacity', 'scheduling_direction' => 'BackwardFromDueDate']);

    expect($run->status->value)->toBe('Completed')->and($run->execution_duration_ms)->toBeInt()->and($run->completed_at)->not->toBeNull();

    $byItem = MrpActionProposal::query()->where('mrp_run_id', $run->id)->get()->keyBy('mfg_item_id');
    $fg = $byItem[$this->fg->id];
    $sub = $byItem[$this->sub->id];
    $rm = $byItem[$this->rm->id];

    expect($fg->proposal_type->value)->toBe('PlannedProductionOrder')
        ->and($fg->suggested_order_qty)->toBe('20.0000')->and($fg->low_level_code)->toBe(0)
        ->and($fg->need_by_date->toDateString())->toBe($this->due->toDateString())
        ->and($fg->product_id)->toBe($this->fg->product_id)->and($fg->bom_version_id)->not->toBeNull()
        ->and($sub->suggested_order_qty)->toBe('40.0000')->and($sub->low_level_code)->toBe(1)
        ->and($sub->pegged_demand_source_ids)->toBe([['type' => 'mrp_proposal', 'id' => (string) $fg->id, 'quantity' => '40.0000']])
        ->and($rm->proposal_type->value)->toBe('PlannedPurchaseRequisition')->and($rm->suggested_order_qty)->toBe('120.0000')->and($rm->low_level_code)->toBe(2)
        ->and($fg->pegged_demand_source_ids[0]['type'])->toBe('sales_order_line');

    $this->getJson("/api/v1/mrp/runs/{$run->id}")->assertOk()
        ->assertJsonPath('data.run.status', 'Completed')
        ->assertJsonPath('data.kpis.proposals_total', 3)
        ->assertJsonPath('data.kpis.by_type.PlannedProductionOrder', 2)
        ->assertJsonPath('data.kpis.by_status.Proposed', 3)
        ->assertJsonMissingPath('data.run.summary_metrics.item_signatures');
    $this->getJson('/api/v1/mrp/runs')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $run->id);
});

it('lists proposals filtered, sorted and paged, defaulting to the latest completed run', function (): void {
    $run = mrpFeatureRun($this);

    $this->getJson('/api/v1/mrp/proposals')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.mrp_run_id', $run->id);
    $this->getJson('/api/v1/mrp/proposals?proposal_type=PlannedPurchaseRequisition')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_code', 'RM-1');
    $this->getJson('/api/v1/mrp/proposals?item_id='.$this->sub->id)->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/mrp/proposals?sort_by=suggested_order_qty&sort_direction=desc')->assertOk()->assertJsonPath('data.0.item_code', 'RM-1');
    $this->getJson('/api/v1/mrp/proposals?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/mrp/proposals?need_by_from='.$this->due->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/mrp/proposals?exception_code=ExcessSupply')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/mrp/proposals?proposal_type=Nonsense')->assertUnprocessable();
});

it('answers 404 for proposals before the first run and for unknown runs', function (): void {
    $this->getJson('/api/v1/mrp/proposals')->assertNotFound();
    $this->getJson('/api/v1/mrp/runs/'.Str::uuid())->assertNotFound();
});

it('returns the pegging tree from a component back to the sales order and forward through the components', function (): void {
    $run = mrpFeatureRun($this);
    $sub = MrpActionProposal::query()->where('mrp_run_id', $run->id)->where('mfg_item_id', $this->sub->id)->firstOrFail();

    $tree = $this->getJson("/api/v1/mrp/proposals/{$sub->id}/pegging")->assertOk()->json('data');

    expect($tree['proposal']['item_code'])->toBe('SUB-1')
        ->and($tree['upstream'][0]['proposal']['item_code'])->toBe('FG-1')
        ->and($tree['upstream'][0]['upstream'][0]['type'])->toBe('sales_order_line')
        ->and($tree['root_demands'][0]['quantity'])->toBe('20.0000')
        ->and($tree['downstream'][0]['item_code'])->toBe('RM-1');
    $this->getJson('/api/v1/mrp/proposals/'.Str::uuid().'/pegging')->assertNotFound();
});

it('validates the run parameters', function (): void {
    $this->postJson('/api/v1/mrp/runs', [])->assertUnprocessable()->assertJsonValidationErrors(['planning_horizon_days']);
    $this->postJson('/api/v1/mrp/runs', ['planning_horizon_days' => 5000])->assertUnprocessable()->assertJsonValidationErrors(['planning_horizon_days']);
    $this->postJson('/api/v1/mrp/runs', ['planning_horizon_days' => 30, 'capacity_scheduling_mode' => 'Nope'])->assertUnprocessable()->assertJsonValidationErrors(['capacity_scheduling_mode']);
});

it('ends the run as Failed with the cycle when the bills of materials loop', function (): void {
    // The BOM service refuses to store a cycle, so the loader is replaced by one that returns a looping snapshot.
    $this->app->bind(MrpSnapshotLoaderInterface::class, fn (): MrpSnapshotLoaderInterface => new class implements MrpSnapshotLoaderInterface
    {
        public function load(string $tenantId, RunParameters $parameters): MrpSnapshot
        {
            $item = static fn (string $id, string $child): ItemPlanningData => new ItemPlanningData($id, $id, $id, ItemPlanningData::Make, 1, components: [new BomLine($child, '1')]);

            return new MrpSnapshot($parameters, ['a' => $item('a', 'b'), 'b' => $item('b', 'a')], [], []);
        }

        public function calendars(array $workCenterIds, CarbonImmutable $from, CarbonImmutable $to): array
        {
            return [];
        }
    });

    $response = $this->postJson('/api/v1/mrp/runs', ['planning_horizon_days' => 60])->assertCreated();

    expect($response->json('data.status'))->toBe('Failed')->and($response->json('data.error_message'))->toContain('Cyclic bill of materials: a -> b -> a');
    expect(MrpActionProposal::query()->count())->toBe(0)->and(MrpRunExecution::query()->count())->toBe(1);
});

it('plans on the supplier customs and shutdown calendars when the run asks for them', function (): void {
    MrpItemParameter::create(['tenant_id' => $this->tenant->id, 'mfg_item_id' => $this->rm->id, 'supplier_country' => 'CH', 'customs_clearance_days' => 3]);

    $plain = mrpFeatureRun($this);
    $customs = mrpFeatureRun($this, ['include_cross_border_customs_lead_time' => true, 'apply_supplier_shutdown_calendars' => true]);

    $release = static fn (MrpRunExecution $run): MrpActionProposal => MrpActionProposal::query()->where('mrp_run_id', $run->id)->where('proposal_type', 'PlannedPurchaseRequisition')->firstOrFail();

    // The tenant is Italian and the supplier Swiss: the customs border adds 3 days, so the order has to go out earlier.
    expect($release($customs)->release_order_by_date < $release($plain)->release_order_by_date)->toBeTrue()
        ->and($release($customs)->exception_messages)->toContain('CustomsLeadTimeApplied')
        ->and($release($plain)->exception_messages)->not->toContain('CustomsLeadTimeApplied');
});

// ---------------------------------------------------------------- firm, release, net change

it('firms proposals and keeps them in the next run as commitments instead of planning them again', function (): void {
    $first = mrpFeatureRun($this);
    $fg = MrpActionProposal::query()->where('mrp_run_id', $first->id)->where('mfg_item_id', $this->fg->id)->firstOrFail();

    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$fg->id], 'action' => 'firm'])->assertOk()->assertJsonPath('data.proposals.0.status', 'Firm');

    $second = mrpFeatureRun($this);

    // The firm order moved into the new run and covers the demand: no second planned order for the finished good.
    expect($fg->refresh()->mrp_run_id)->toBe($second->id)
        ->and(MrpActionProposal::query()->where('mrp_run_id', $second->id)->where('mfg_item_id', $this->fg->id)->count())->toBe(1)
        ->and(MrpActionProposal::query()->where('mrp_run_id', $second->id)->where('mfg_item_id', $this->sub->id)->count())->toBe(1)
        ->and($second->summary_metrics['firm_carried_over'])->toBe(1);
});

it('releases proposals into one purchase requisition and production orders and records the links', function (): void {
    $run = mrpFeatureRun($this);
    $ids = MrpActionProposal::query()->where('mrp_run_id', $run->id)->pluck('id')->all();

    $response = $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => $ids, 'action' => 'release'])->assertOk()
        ->assertJsonPath('data.processed', 3)->assertJsonCount(1, 'data.purchase_requisition_ids')->assertJsonCount(2, 'data.production_order_ids');

    $requisition = PurchaseRequisition::query()->findOrFail($response->json('data.purchase_requisition_ids.0'));
    $order = MfgProductionOrder::query()->where('mfg_item_id', $this->fg->id)->firstOrFail();

    expect($requisition->status)->toBe('draft')->and($requisition->lines()->count())->toBe(1)->and($requisition->lines()->first()->quantity_requested)->toBe('120.0000')
        ->and($order->target_quantity)->toBe('20.0000')->and($order->status)->toBe('planned')
        ->and(MrpActionProposal::query()->where('mrp_run_id', $run->id)->where('status', 'Released')->count())->toBe(3);

    $fg = MrpActionProposal::query()->where('mfg_item_id', $this->fg->id)->firstOrFail();
    expect($fg->firm_reference_type)->toBe('production_order')->and($fg->firm_reference_id)->toBe((string) $order->id)->and($fg->released_at)->not->toBeNull();

    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$fg->id], 'action' => 'release'])->assertUnprocessable();
});

it('dismisses proposals, refuses to firm advice on open orders and treats a selection all or nothing', function (): void {
    $run = mrpFeatureRun($this);
    [$one, $two] = MrpActionProposal::query()->where('mrp_run_id', $run->id)->pluck('id')->take(2)->all();

    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$one], 'action' => 'dismiss'])->assertOk()->assertJsonPath('data.proposals.0.status', 'Dismissed');
    // The dismissed one cannot be firmed, so the whole selection fails and the other stays untouched.
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$two, $one], 'action' => 'firm'])->assertUnprocessable();
    expect(MrpActionProposal::query()->findOrFail($two)->status->value)->toBe('Proposed');

    $advice = MrpActionProposal::create([
        'tenant_id' => $this->tenant->id, 'mrp_run_id' => $run->id, 'mfg_item_id' => $this->rm->id, 'proposal_type' => 'CancelExcessOrder', 'status' => 'Proposed',
        'need_by_date' => now()->toDateString(), 'release_order_by_date' => now()->toDateString(),
    ]);
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$advice->id], 'action' => 'release'])->assertUnprocessable();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$advice->id], 'action' => 'dismiss'])->assertOk();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [(string) Str::uuid()], 'action' => 'firm'])->assertNotFound();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [], 'action' => 'firm'])->assertUnprocessable();
});

it('keeps the proposals of unchanged items in a net-change run and replaces the ones whose inputs changed', function (): void {
    $other = mrpFeatureItem($this, 'FG-2', 'finished_good', ['lead_time_days' => 3, 'procurement_type' => 'make']);
    mrpFeatureDemand($this, $other, '5', $this->due);
    $first = mrpFeatureRun($this);
    $fgProposal = MrpActionProposal::query()->where('mrp_run_id', $first->id)->where('mfg_item_id', $this->fg->id)->firstOrFail();
    $otherProposal = MrpActionProposal::query()->where('mrp_run_id', $first->id)->where('mfg_item_id', $other->id)->firstOrFail();

    // Only FG-2 gets more demand: FG-1 and its components keep their proposals (same ids, moved into the new run).
    mrpFeatureDemand($this, $other, '7', $this->due);
    $second = mrpFeatureRun($this, ['run_mode' => 'NetChange']);

    expect(MrpActionProposal::query()->whereKey($fgProposal->id)->value('mrp_run_id'))->toBe($second->id)
        ->and($second->summary_metrics['kept_proposals'])->toBe(3)
        ->and(MrpActionProposal::query()->whereKey($otherProposal->id)->value('mrp_run_id'))->toBe($first->id)
        ->and(MrpActionProposal::query()->where('mrp_run_id', $second->id)->where('mfg_item_id', $other->id)->firstOrFail()->suggested_order_qty)->toBe('12.0000')
        ->and(MrpActionProposal::query()->where('mrp_run_id', $second->id)->count())->toBe(4);
});

// ---------------------------------------------------------------- capacity, Gantt

/**
 * Two finished goods of 100 units routed on the same work centre (400 minutes each) due the same Friday; the work centre
 * offers 480 minutes a day.
 *
 * @return array{0: MfgWorkCenter, 1: MfgWorkCenter, 2: MfgItem, 3: MfgItem}
 */
function mrpFeatureCapacitySetUp(object $test): array
{
    $wc1 = mfgWorkCenter($test, 'WC-1', '50', '10');
    $wc2 = mfgWorkCenter($test, 'WC-2', '50', '10');
    $a = mrpFeatureItem($test, 'CAP-A', 'finished_good', ['lead_time_days' => 0, 'procurement_type' => 'make']);
    $b = mrpFeatureItem($test, 'CAP-B', 'finished_good', ['lead_time_days' => 0, 'procurement_type' => 'make']);
    mrpFeatureRouting($test, $a, $wc1, '0', '4');
    mrpFeatureRouting($test, $b, $wc1, '0', '4');
    mrpFeatureDemand($test, $a, '100', $test->due);
    mrpFeatureDemand($test, $b, '100', $test->due);

    return [$wc1, $wc2, $a, $b];
}

it('reports an overloaded bucket under infinite capacity, levels it with the finite capacity and shows it on the Gantt', function (): void {
    [$wc1, , $a, $b] = mrpFeatureCapacitySetUp($this);
    $run = mrpFeatureRun($this, ['capacity_scheduling_mode' => 'InfiniteCapacity']);

    $bucket = MrpWorkCenterCapacityBucket::query()->where('mrp_run_id', $run->id)->where('work_center_id', $wc1->id)->firstOrFail();
    expect($bucket->bucket_date->toDateString())->toBe($this->due->toDateString())
        ->and($bucket->available_minutes)->toBe(480)->and($bucket->loaded_minutes)->toBe(800)
        ->and($bucket->load_percentage)->toBe('166.67')->and($bucket->is_overloaded)->toBeTrue()
        ->and($bucket->assigned_proposal_ids)->toHaveCount(2)
        ->and($run->summary_metrics['overloaded_buckets'])->toBe(1)
        ->and(MrpActionProposal::query()->where('mrp_run_id', $run->id)->where('mfg_item_id', $a->id)->firstOrFail()->exception_messages)->toContain('CapacityOverload');
    $this->getJson("/api/v1/mrp/proposals?exception_code=CapacityOverload&run_id={$run->id}")->assertOk()->assertJsonCount(2, 'data');

    $gantt = $this->getJson("/api/v1/mrp/runs/{$run->id}/gantt?from={$this->due->subWeek()->toDateString()}&to={$this->due->addDay()->toDateString()}")->assertOk()->json('data');
    expect($gantt['work_centers'])->toHaveCount(1)->and($gantt['work_centers'][0]['code'])->toBe('WC-1')
        ->and($gantt['work_centers'][0]['buckets'][0]['is_overloaded'])->toBeTrue()
        ->and($gantt['work_centers'][0]['blocks'])->toHaveCount(2);

    $this->postJson("/api/v1/mrp/runs/{$run->id}/auto-level")->assertOk()
        ->assertJsonPath('data.overloaded_buckets_before', 1)->assertJsonPath('data.overloaded_buckets_after', 0)->assertJsonPath('data.leveled_proposals', 2);

    $loads = MrpWorkCenterCapacityBucket::query()->where('mrp_run_id', $run->id)->orderBy('bucket_date')->pluck('loaded_minutes', 'bucket_date')->all();
    expect(array_sum($loads))->toBe(800)->and(max($loads))->toBeLessThanOrEqual(480)
        ->and(MrpActionProposal::query()->where('mrp_run_id', $run->id)->where('mfg_item_id', $b->id)->firstOrFail()->exception_messages)->not->toContain('CapacityOverload');
});

it('plans with the finite capacity from the start when the run asks for it', function (): void {
    mrpFeatureCapacitySetUp($this);

    $run = mrpFeatureRun($this, ['capacity_scheduling_mode' => 'FiniteCapacityConstraint']);

    expect($run->summary_metrics['overloaded_buckets'])->toBe(0)->and($run->summary_metrics['max_load_percentage'])->toBe('100.00');
});

it('reschedules a Gantt block to another day and to an alternative work centre, and refuses anything else', function (): void {
    [$wc1, $wc2, $a] = mrpFeatureCapacitySetUp($this);
    MrpWorkCenterAlternative::create(['tenant_id' => $this->tenant->id, 'work_center_id' => $wc1->id, 'alternative_work_center_id' => $wc2->id]);
    $run = mrpFeatureRun($this, ['capacity_scheduling_mode' => 'InfiniteCapacity']);
    $proposal = MrpActionProposal::query()->where('mrp_run_id', $run->id)->where('mfg_item_id', $a->id)->firstOrFail();
    $block = MrpScheduleBlock::query()->where('proposal_id', $proposal->id)->firstOrFail();

    // Move to the alternative work centre: the Friday overload disappears.
    $response = $this->postJson("/api/v1/mrp/schedule-blocks/{$block->id}/reschedule", ['start_date' => $this->due->toDateString(), 'work_center_id' => $wc2->id])->assertOk()
        ->assertJsonPath('data.block.work_center_id', $wc2->id)->assertJsonPath('data.block.original_work_center_id', $wc1->id)->assertJsonPath('data.block.is_manual', true)
        ->assertJsonPath('data.capacity.overloaded_buckets', 0);
    expect($response->json('data.proposal.work_center_id'))->toBe($wc2->id)
        ->and(MrpActionProposal::query()->findOrFail($proposal->id)->exception_messages)->not->toContain('CapacityOverload');

    // Drag onto a day, overloading nothing: the block starts on the Monday of the due week (480 + 0 free).
    $monday = $this->due->startOfWeek();
    $this->postJson("/api/v1/mrp/schedule-blocks/{$block->id}/reschedule", ['start_date' => $monday->toDateString()])->assertOk()
        ->assertJsonPath('data.block.start_date', $monday->toDateString());

    $wc3 = mfgWorkCenter($this, 'WC-3', '1', '1');
    $this->postJson("/api/v1/mrp/schedule-blocks/{$block->id}/reschedule", ['work_center_id' => $wc3->id])->assertUnprocessable();
    $this->postJson("/api/v1/mrp/schedule-blocks/{$block->id}/reschedule", [])->assertUnprocessable();
    $this->postJson('/api/v1/mrp/schedule-blocks/'.Str::uuid().'/reschedule', ['start_date' => $monday->toDateString()])->assertNotFound();

    // Manual placements stay where they are when the rest is leveled.
    $this->postJson("/api/v1/mrp/runs/{$run->id}/auto-level")->assertOk()->assertJsonPath('data.leveled_proposals', 1);
    expect(MrpScheduleBlock::query()->findOrFail($block->id)->start_date->toDateString())->toBe($monday->toDateString());
});

it('refuses to move blocks of released proposals', function (): void {
    mrpFeatureCapacitySetUp($this);
    $run = mrpFeatureRun($this);
    $block = MrpScheduleBlock::query()->where('mrp_run_id', $run->id)->firstOrFail();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$block->proposal_id], 'action' => 'release'])->assertOk();

    $this->postJson("/api/v1/mrp/schedule-blocks/{$block->id}/reschedule", ['start_date' => $this->due->subDay()->toDateString()])->assertUnprocessable();
});

// ---------------------------------------------------------------- access

it('lets every reader see MRP but only planners run it and only release roles act on proposals', function (): void {
    $run = mrpFeatureRun($this);
    $proposal = MrpActionProposal::query()->where('mrp_run_id', $run->id)->firstOrFail();

    mfgActAs($this, $this->reader);
    $this->getJson('/api/v1/mrp/runs')->assertOk();
    $this->getJson('/api/v1/mrp/proposals')->assertOk();
    $this->postJson('/api/v1/mrp/runs', ['planning_horizon_days' => 30])->assertForbidden();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$proposal->id], 'action' => 'firm'])->assertForbidden();

    mfgActAs($this, $this->member);
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$proposal->id], 'action' => 'firm'])->assertForbidden();

    mfgActAs($this, $this->production);
    $this->postJson('/api/v1/mrp/runs', ['planning_horizon_days' => 30])->assertCreated();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$proposal->id], 'action' => 'firm'])->assertOk();
});

it('never shows or touches the runs and proposals of another company', function (): void {
    $run = mrpFeatureRun($this);
    $proposal = MrpActionProposal::query()->where('mrp_run_id', $run->id)->firstOrFail();

    $stranger = mfgUser($this, 'owner', $this->otherTenant);
    mfgActAs($this, $stranger, $this->otherTenant);

    $this->getJson('/api/v1/mrp/runs')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson("/api/v1/mrp/runs/{$run->id}")->assertNotFound();
    $this->getJson("/api/v1/mrp/proposals/{$proposal->id}/pegging")->assertNotFound();
    $this->postJson('/api/v1/mrp/proposals/actions', ['proposal_ids' => [$proposal->id], 'action' => 'dismiss'])->assertNotFound();
    $this->postJson("/api/v1/mrp/runs/{$run->id}/auto-level")->assertNotFound();
    expect(MrpActionProposal::query()->count())->toBe(0);
});
