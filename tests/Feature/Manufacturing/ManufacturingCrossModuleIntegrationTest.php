<?php

declare(strict_types=1);

use App\Models\Contact;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Listeners\WorkflowApprovalManufacturingListener;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Services\LegacyProductionOrderMigrator;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
});

/**
 * A finished good whose second operation is subcontracted at 5.00 per unit and consumes 2 steel per unit.
 *
 * @return array{fg: MfgItem, steel: MfgItem, subcontractor: Contact, store: Warehouse}
 */
function mfgSubcontractSetUp(object $test): array
{
    $fg = mfgItem($test, 'FG-SUB', 'finished_good');
    $steel = mfgItem($test, 'RM-SUB', 'raw_material', '10.00');
    $wc = mfgWorkCenter($test, 'WC-SUB', '60.00', '30.00');
    mfgStock($steel, $test->warehouse, '100', '10.00');
    $subcontractor = Contact::factory()->create(['tenant_id' => $test->tenant->id]);
    $store = Warehouse::factory()->create(['tenant_id' => $test->tenant->id, 'is_primary' => false, 'name' => 'Galvanica Rossi']);

    $bomId = $test->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'Sub', 'components' => [['component_item_id' => $steel->id, 'quantity_per' => '2', 'operation_sequence' => 20]]])->json('data.id');
    $test->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();
    $test->postJson('/api/v1/manufacturing/routings', ['mfg_item_id' => $fg->id, 'name' => 'Sub', 'operations' => [
        ['operation_code' => 'OP10', 'name' => 'Cut', 'work_center_id' => $wc->id, 'run_time_minutes_per_unit' => '1'],
        ['operation_code' => 'OP20', 'name' => 'Galvanize', 'work_center_id' => $wc->id, 'is_subcontracted' => true, 'subcontractor_contact_id' => $subcontractor->id, 'subcontract_unit_cost' => '5.00'],
    ]])->assertCreated();

    return ['fg' => $fg, 'steel' => $steel, 'subcontractor' => $subcontractor, 'store' => $store];
}

/**
 * A released order of the subcontracted item and its subcontracted operation id.
 *
 * @return array{0: string, 1: string}
 */
function mfgSubcontractOrder(object $test, MfgItem $fg, string $quantity): array
{
    $orderId = (string) $test->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $fg->id, 'target_quantity' => $quantity, 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->addDays(5)->toDateString()])->json('data.id');
    $operations = collect($test->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->assertOk()->json('data.operations'))->keyBy('sequence');

    return [$orderId, (string) $operations[20]['id']];
}

test('subcontracting sends components to the subcontractor, consumes them on receipt and accrues the processing to GR/IR', function (): void {
    $setUp = mfgSubcontractSetUp($this);
    [$orderId, $op20] = mfgSubcontractOrder($this, $setUp['fg'], '10');

    $op10 = MfgProductionOrderOperation::query()->where('production_order_id', $orderId)->where('sequence', 10)->value('id');
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/subcontract-orders", ['production_order_operation_id' => $op10, 'subcontractor_contact_id' => $setUp['subcontractor']->id, 'subcontract_warehouse_id' => $setUp['store']->id, 'quantity' => '10'])
        ->assertUnprocessable()->assertJsonValidationErrors(['production_order_operation_id']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/subcontract-orders", ['production_order_operation_id' => $op20, 'subcontractor_contact_id' => $setUp['subcontractor']->id, 'subcontract_warehouse_id' => $setUp['store']->id, 'quantity' => '11'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);

    $subId = $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/subcontract-orders", ['production_order_operation_id' => $op20, 'subcontractor_contact_id' => $setUp['subcontractor']->id, 'subcontract_warehouse_id' => $setUp['store']->id, 'quantity' => '10'])
        ->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.unit_cost', '5.0000')->assertJsonPath('data.lines.0.quantity_sent', '20.0000')->json('data.id');
    expect(mfgQuantities($setUp['steel'], $this->warehouse))->toBe(['on_hand' => '80.0000', 'reserved' => '0.0000'])
        ->and(mfgQuantities($setUp['steel'], $setUp['store'])['on_hand'])->toBe('20.0000')
        ->and(mfgLedgerBalance((string) $this->tenant->id, '1210'))->toBe('0.00');

    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/complete")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/subcontract-orders/{$subId}/receive", ['good_quantity' => '11'])->assertUnprocessable()->assertJsonValidationErrors(['good_quantity']);
    $this->postJson("/api/v1/manufacturing/subcontract-orders/{$subId}/receive", ['good_quantity' => '6'])->assertOk()->assertJsonPath('data.status', 'partially_received')->assertJsonPath('data.total_cost', '30.0000');
    $this->postJson("/api/v1/manufacturing/subcontract-orders/{$subId}/cancel")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/subcontract-orders/{$subId}/receive", ['good_quantity' => '4'])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.lines.0.quantity_consumed', '20.0000');

    expect(mfgQuantities($setUp['steel'], $setUp['store'])['on_hand'])->toBe('0.0000')
        ->and(MfgProductionOrderOperation::query()->findOrFail($op20)->status)->toBe('completed')
        ->and(mfgLedgerBalance((string) $this->tenant->id, '2010'))->toBe('-50.00');

    // Standard: 20 material + 1 labour + 0.50 overhead + 5 subcontracting per unit; actual 200 + 50, no labour confirmed.
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/receipts", ['quantity' => '10'])->assertCreated()->assertJsonPath('data.total_cost', '265.0000');
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/complete")->assertOk();
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/close")->assertOk()
        ->assertJsonPath('data.material_variance', '0.0000')
        ->assertJsonPath('data.subcontract_variance', '0.0000')
        ->assertJsonPath('data.labor_variance', '-10.0000')
        ->assertJsonPath('data.overhead_variance', '-5.0000');
    expect(mfgLedgerBalance((string) $this->tenant->id, '1210'))->toBe('0.00')
        ->and(mfgLedgerBalance((string) $this->tenant->id, '5920'))->toBe('-10.00');

    $this->getJson('/api/v1/manufacturing/subcontract-orders?status=received')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/manufacturing/subcontract-orders/{$subId}")->assertOk()->assertJsonPath('data.received_quantity', '10.0000');
});

test('a subcontract order with nothing received is cancelled and its components come back', function (): void {
    $setUp = mfgSubcontractSetUp($this);
    [$orderId, $op20] = mfgSubcontractOrder($this, $setUp['fg'], '5');

    $subId = $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/subcontract-orders", ['production_order_operation_id' => $op20, 'subcontractor_contact_id' => $setUp['subcontractor']->id, 'subcontract_warehouse_id' => $setUp['store']->id, 'quantity' => '5', 'unit_cost' => '4.50'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/manufacturing/subcontract-orders/{$subId}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.lines.0.quantity_returned', '10.0000');
    $this->postJson("/api/v1/manufacturing/subcontract-orders/{$subId}/receive", ['good_quantity' => '1'])->assertUnprocessable();

    expect(mfgQuantities($setUp['steel'], $this->warehouse)['on_hand'])->toBe('100.0000')
        ->and(mfgQuantities($setUp['steel'], $setUp['store'])['on_hand'])->toBe('0.0000');
});

test('production events reach the transactional outbox once', function (): void {
    $product = mfgProductSetUp($this);
    $orderId = $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $product['fg']->id, 'target_quantity' => '1', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->json('data.id');
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->assertOk();

    foreach (['mfg.bom.created', 'mfg.production_order.created', 'mfg.production_order.released'] as $eventName) {
        expect(TransactionalOutbox::query()->where('event_type', $eventName)->count())->toBeGreaterThanOrEqual(1);
    }
    expect(TransactionalOutbox::query()->where('event_type', 'mfg.production_order.released')->where('aggregate_id', $orderId)->count())->toBe(1);
});

test('an approval workflow decides submitted engineering changes in the name of its last actor', function (): void {
    $fg = mfgItem($this, 'FG-WF', 'finished_good');
    $rm = mfgItem($this, 'RM-WF', 'raw_material');
    $bomId = $this->postJson('/api/v1/manufacturing/boms', ['mfg_item_id' => $fg->id, 'name' => 'WF', 'components' => [['component_item_id' => $rm->id, 'quantity_per' => '1']]])->json('data.id');
    $this->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();

    $definition = WorkflowDefinition::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'code' => 'MFG_ECO', 'name' => 'ECO approval', 'target_entity_type' => MfgEngineeringChangeOrder::class, 'version' => 1, 'status' => 'published', 'is_active' => true]);
    $listener = app(WorkflowApprovalManufacturingListener::class);
    $decide = function (string $status) use ($bomId, $rm, $definition): MfgEngineeringChangeOrder {
        $ecoId = $this->postJson('/api/v1/manufacturing/engineering-changes', ['bom_id' => $bomId, 'title' => 'WF', 'reason' => 'WF', 'components' => [['action' => 'update', 'component_item_id' => $rm->id, 'quantity_per' => '2']]])->json('data.id');
        $this->postJson("/api/v1/manufacturing/engineering-changes/{$ecoId}/submit")->assertOk();

        return MfgEngineeringChangeOrder::query()->findOrFail($ecoId)->setAttribute('workflow_instance_id', WorkflowInstance::create([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'workflow_definition_id' => $definition->id, 'workflow_definition_version' => 1,
            'approvable_type' => MfgEngineeringChangeOrder::class, 'approvable_id' => $ecoId, 'status' => $status, 'initiator_user_id' => $this->owner->id, 'updated_by' => $this->production->id,
        ])->id);
    };

    $approved = $decide('completed');
    $listener->handleCompleted(new WorkflowInstanceCompletedEvent($approved->getAttribute('workflow_instance_id'), 'completed', (string) $this->tenant->id, (string) $this->tenant->id));
    expect($approved->refresh()->status)->toBe('approved')->and($approved->approved_by)->toBe($this->production->id);

    // A redelivered event changes nothing.
    $listener->handleCompleted(new WorkflowInstanceCompletedEvent($approved->getAttribute('workflow_instance_id') ?? (string) WorkflowInstance::query()->where('approvable_id', $approved->id)->value('id'), 'completed', (string) $this->tenant->id, (string) $this->tenant->id));
    expect($approved->refresh()->status)->toBe('approved');

    $rejected = $decide('rejected');
    $listener->handleRejected(new WorkflowInstanceRejectedEvent($rejected->getAttribute('workflow_instance_id'), (string) $this->tenant->id, (string) $this->tenant->id));
    expect($rejected->refresh()->status)->toBe('rejected')->and($rejected->rejection_reason)->toBe('Rejected by workflow');
});

test('legacy bills of materials, production orders and stages are copied once into the module, keyed on their ids', function (): void {
    $productId = (string) Str::uuid7();
    $rawId = (string) Str::uuid7();
    foreach ([[$productId, 'LEG-FG', '12.50'], [$rawId, 'LEG-RM', '3.00']] as [$id, $sku, $cost]) {
        DB::table('products')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'sku' => $sku, 'name' => $sku, 'cost_price' => $cost, 'created_at' => now(), 'updated_at' => now()]);
    }
    $bomId = (string) Str::uuid7();
    $orderId = (string) Str::uuid7();
    $stageId = (string) Str::uuid7();
    DB::table('bills_of_materials')->insert(['id' => $bomId, 'tenant_id' => $this->tenant->id, 'finished_product_id' => $productId, 'version' => '2.0', 'name' => 'Legacy BOM', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('bom_items')->insert(['id' => (string) Str::uuid7(), 'bill_of_materials_id' => $bomId, 'raw_product_id' => $rawId, 'quantity' => '3', 'scrap_percentage' => '5', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('production_orders')->insert(['id' => $orderId, 'tenant_id' => $this->tenant->id, 'bill_of_materials_id' => $bomId, 'order_number' => 'PO-LEG-1', 'target_quantity' => '8', 'produced_quantity' => '2', 'start_date' => now()->toDateString(), 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('production_order_stages')->insert(['id' => $stageId, 'production_order_id' => $orderId, 'stage_name' => 'Assembly', 'sequence' => 1, 'planned_hours' => '1.50', 'actual_hours' => '0.25', 'status' => 'running', 'created_at' => now(), 'updated_at' => now()]);

    $migrator = app(LegacyProductionOrderMigrator::class);
    expect($migrator->migrate())->toBe(['boms' => 1, 'components' => 1, 'orders' => 1, 'operations' => 1, 'items' => 2])
        ->and(app(LegacyProductionOrderMigrator::class)->migrate())->toBe(['boms' => 0, 'components' => 0, 'orders' => 0, 'operations' => 0, 'items' => 0]);

    $order = MfgProductionOrder::query()->findOrFail($orderId);
    $item = MfgItem::query()->where('product_id', $productId)->firstOrFail();
    $operation = MfgProductionOrderOperation::query()->findOrFail($stageId);
    expect($order->status)->toBe('in_progress')
        ->and($order->bom_id)->toBe($bomId)
        ->and($order->completed_quantity)->toBe('2.0000')
        ->and($order->planned_end_date->toDateString())->toBe(now()->toDateString())
        ->and($item->default_bom_id)->toBe($bomId)
        ->and($item->standard_cost)->toBe('12.5000')
        ->and(MfgBom::query()->findOrFail($bomId)->components()->value('scrap_percentage'))->toBe('5.00')
        ->and($operation->status)->toBe('in_progress')
        ->and($operation->planned_run_minutes)->toBe('90.00')
        ->and($operation->actual_run_minutes)->toBe('15.00')
        ->and(DB::table('production_orders')->where('id', $orderId)->exists())->toBeTrue();

    $this->getJson("/api/v1/manufacturing/production-orders/{$orderId}")->assertOk()->assertJsonPath('data.order_number', 'PO-LEG-1');
});
