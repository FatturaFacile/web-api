<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\Inventory\Models\StockMovement;
use Modules\Manufacturing\Models\MfgMaterialMovement;
use Modules\Manufacturing\Models\MfgProductionCost;
use Modules\Manufacturing\Models\MfgProductionOrder;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    $this->product = mfgProductSetUp($this);
});

/**
 * A released order of 10 pumps, with its line and operation ids.
 *
 * @return array{id: string, steel_line: string, seal_line: string, op10: string, op20: string}
 */
function mfgReleasedOrder(object $test, string $quantity = '10'): array
{
    $orderId = (string) $test->postJson('/api/v1/manufacturing/production-orders', [
        'mfg_item_id' => $test->product['fg']->id,
        'target_quantity' => $quantity,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(5)->toDateString(),
    ])->assertCreated()->assertJsonPath('data.status', 'planned')->json('data.id');

    $released = $test->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->assertOk()->assertJsonPath('data.status', 'released');
    $lines = collect($released->json('data.lines'))->keyBy('mfg_item_id');
    $operations = collect($released->json('data.operations'))->keyBy('sequence');

    return [
        'id' => $orderId,
        'steel_line' => (string) $lines[$test->product['steel']->id]['id'],
        'seal_line' => (string) $lines[$test->product['seal']->id]['id'],
        'op10' => (string) $operations[10]['id'],
        'op20' => (string) $operations[20]['id'],
    ];
}

test('a production order runs end to end on the single stock ledger and settles WIP into variances in the ledger', function (): void {
    $order = mfgReleasedOrder($this);
    $id = $order['id'];

    // Release: standard frozen from the order's BOM and routing (material 22, labour 120/10, overhead 60/10 per unit),
    // components reserved in the warehouse.
    $this->getJson("/api/v1/manufacturing/production-orders/{$id}")->assertOk()
        ->assertJsonPath('data.standard_unit_cost', '40.000000')
        ->assertJsonPath('data.standard_cost_total', '400.0000');
    expect(mfgQuantities($this->product['steel'], $this->warehouse)['reserved'])->toBe('20.0000')
        ->and(mfgQuantities($this->product['seal'], $this->warehouse)['reserved'])->toBe('10.0000');

    // Issue 21 steel (one over the requirement): refused without the explicit over-issue, then booked at 10.00.
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/issues", ['lines' => [['line_id' => $order['steel_line'], 'quantity' => '21']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/issues", ['allow_over_issue' => true, 'lines' => [['line_id' => $order['steel_line'], 'quantity' => '21']]])
        ->assertCreated()->assertJsonPath('data.0.total_cost', '210.0000')->assertJsonPath('data.0.movement_type', 'issue');

    // OP10: 30 setup + 66 run minutes (labour 96 min at 60/h, overhead 96 min at 30/h), seal backflushed for 10 units.
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$order['op10']}/confirmations", ['setup_minutes' => '30', 'run_minutes' => '66', 'good_quantity' => '10', 'is_final' => true])
        ->assertCreated()->assertJsonPath('data.labor_cost', '96.0000')->assertJsonPath('data.overhead_cost', '48.0000');
    // OP20: 30 run minutes.
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/operations/{$order['op20']}/confirmations", ['run_minutes' => '30', 'good_quantity' => '10', 'is_final' => true])
        ->assertCreated()->assertJsonPath('data.labor_cost', '30.0000');

    // Receipt of 10 at standard 40.00.
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/receipts", ['quantity' => '10'])
        ->assertCreated()->assertJsonPath('data.total_cost', '400.0000')->assertJsonPath('data.movement_type', 'receipt');

    $this->getJson("/api/v1/manufacturing/production-orders/{$id}/cost-rollup")->assertOk()
        ->assertJsonPath('data.elements.material.actual', '230.00')
        ->assertJsonPath('data.elements.material.variance', '10.00')
        ->assertJsonPath('data.elements.labor.actual', '126.00')
        ->assertJsonPath('data.elements.overhead.actual', '63.00')
        ->assertJsonPath('data.totals.wip_balance', '19.00')
        ->assertJsonPath('data.is_closed', false);

    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    $this->postJson("/api/v1/manufacturing/production-orders/{$id}/close")->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.material_variance', '10.0000')
        ->assertJsonPath('data.labor_variance', '6.0000')
        ->assertJsonPath('data.overhead_variance', '3.0000')
        ->assertJsonPath('data.variance_total', '19.0000')
        ->assertJsonPath('data.wip_balance', '0.00');

    // Stock: components consumed, output received, reservations gone.
    expect(mfgQuantities($this->product['steel'], $this->warehouse))->toBe(['on_hand' => '79.0000', 'reserved' => '0.0000'])
        ->and(mfgQuantities($this->product['seal'], $this->warehouse))->toBe(['on_hand' => '90.0000', 'reserved' => '0.0000'])
        ->and(mfgQuantities($this->product['fg'], $this->warehouse)['on_hand'])->toBe('10.0000')
        ->and(StockMovement::withoutGlobalScopes()->where('origin_document_type', 'mfg_production_order')->where('origin_document_id', $id)->count())->toBe(3);

    // Ledger: WIP cleared, inventory + issues - receipt, absorption and variances balanced.
    $tenantId = (string) $this->tenant->id;
    expect(mfgLedgerBalance($tenantId, '1210'))->toBe('0.00')
        ->and(mfgLedgerBalance($tenantId, '1200'))->toBe('170.00')
        ->and(mfgLedgerBalance($tenantId, '5810'))->toBe('-126.00')
        ->and(mfgLedgerBalance($tenantId, '5820'))->toBe('-63.00')
        ->and(mfgLedgerBalance($tenantId, '5910'))->toBe('10.00')
        ->and(mfgLedgerBalance($tenantId, '5920'))->toBe('6.00')
        ->and(mfgLedgerBalance($tenantId, '5930'))->toBe('3.00')
        ->and(MfgProductionCost::query()->where('production_order_id', $id)->where('is_posted_to_gl', true)->count())->toBe(4);

    $this->getJson("/api/v1/manufacturing/production-orders/{$id}/cost-rollup")->assertOk()
        ->assertJsonPath('data.is_closed', true)
        ->assertJsonPath('data.totals.variance', '19.00')
        ->assertJsonCount(6, 'data.journal_entry_ids');
});

test('a posting date outside an open fiscal period rolls back the stock movement and the journal entry', function (): void {
    $order = mfgReleasedOrder($this);
    FiscalPeriod::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['status' => 'closed']);

    $this->postJson("/api/v1/manufacturing/production-orders/{$order['id']}/issues", ['lines' => [['line_id' => $order['steel_line'], 'quantity' => '5']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['posting_date']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$order['id']}/issues", ['posting_date' => now()->addYears(3)->toDateString(), 'lines' => [['line_id' => $order['steel_line'], 'quantity' => '5']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['posting_date']);

    expect(MfgMaterialMovement::query()->count())->toBe(0)
        ->and(mfgQuantities($this->product['steel'], $this->warehouse)['on_hand'])->toBe('100.0000')
        ->and(MfgProductionOrder::query()->find($order['id'])->actual_material_cost)->toBe('0.0000');
});

test('the standard frozen at release comes from the cost rollup when the item has one', function (): void {
    $this->postJson("/api/v1/manufacturing/items/{$this->product['fg']->id}/cost-rollup")->assertOk()
        ->assertJsonPath('data.material', '22.0000')
        ->assertJsonPath('data.labor', '39.0000')
        ->assertJsonPath('data.overhead', '19.5000')
        ->assertJsonPath('data.total', '80.5000');

    $order = mfgReleasedOrder($this, '4');

    $this->getJson("/api/v1/manufacturing/production-orders/{$order['id']}")->assertOk()
        ->assertJsonPath('data.standard_unit_cost', '80.500000')
        ->assertJsonPath('data.standard_cost_total', '322.0000');
});

test('closing an order needs a manufacturing manager and a completed order', function (): void {
    $order = mfgReleasedOrder($this);

    $this->postJson("/api/v1/manufacturing/production-orders/{$order['id']}/close")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/v1/manufacturing/production-orders/{$order['id']}/complete")->assertOk();

    mfgActAs($this, $this->member);
    $this->postJson("/api/v1/manufacturing/production-orders/{$order['id']}/close")->assertForbidden();

    mfgActAs($this, $this->production);
    $this->postJson("/api/v1/manufacturing/production-orders/{$order['id']}/close")->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.variance_total', '0.0000');
});
