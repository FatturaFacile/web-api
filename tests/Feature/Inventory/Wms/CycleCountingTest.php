<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\CycleCountPlan;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Services\InventoryCountService;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\Models\WorkflowInstance;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, (int) now()->year);
    $this->warehouse = logisticsWarehouse();
    $this->bin = logisticsBin($this->warehouse);
});

test('a frozen count blocks movements, recounts keep the first count and the approval posts stock and ledger', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '2.5']);
    wmsReceive($this->bin, $item, '10');

    $session = $this->postJson('/api/v1/inventory/counts', [
        'warehouse_id' => $this->warehouse->id, 'count_type' => 'full_physical', 'scheduled_date' => now()->toDateString(), 'freezes_stock' => true,
    ])->assertCreated()->assertJsonPath('freezes_stock', true)->json();

    expect($session['session_number'])->toStartWith('CNT-');

    // The freeze: nothing moves in the counted warehouse, not even a delivery.
    expect(fn () => wmsReceive($this->bin, $item, '1'))->toThrow(StockRuleViolation::class);

    $line = InventoryCountLine::query()->where('inventory_count_session_id', $session['id'])->sole();
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-count", ['session_line_id' => $line->id, 'counted_quantity' => 6])->assertOk();
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/recount", ['line_ids' => [$line->id]])->assertOk()->assertJsonPath('recount_lines', 1);

    expect((string) $line->fresh()->first_counted_quantity)->toBe('6.0000')
        ->and($line->fresh()->counted_quantity)->toBeNull()
        ->and($line->fresh()->recount_count)->toBe(1);

    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-count", ['session_line_id' => $line->id, 'counted_quantity' => 8])->assertOk();

    $adjustment = $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertOk()->assertJsonPath('status', 'approved')->json();

    expect(wmsOnHand($this->bin, $item))->toBe('8.0000')
        ->and(InventoryCountSession::query()->findOrFail($session['id'])->status)->toBe('reconciled')
        ->and((string) InventoryAdjustment::query()->findOrFail($adjustment['id'])->net_valuation_change)->toBe('-5.00')
        ->and($adjustment['adjustment_number'])->toStartWith('ADJ-');

    $entry = JournalEntry::query()->where('source_document_type', 'inventory_adjustment')->where('source_document_id', $adjustment['id'])->sole();
    expect((float) $entry->total_amount)->toBe(5.0);

    // The freeze ended with the approval.
    wmsReceive($this->bin, $item, '1');
    expect(wmsOnHand($this->bin, $item))->toBe('9.0000');
});

test('differences above the approval threshold wait for the workflow and keep the stock frozen', function (): void {
    config(['inventory.adjustment_approval_threshold' => '1.00']);
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '2']);
    wmsReceive($this->bin, $item, '5');
    $session = $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => now()->toDateString(), 'freezes_stock' => true])->json();
    $line = InventoryCountLine::query()->where('inventory_count_session_id', $session['id'])->sole();
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-count", ['session_line_id' => $line->id, 'counted_quantity' => 3])->assertOk();

    $mock = mock(WorkflowEngineInterface::class);
    $mock->shouldReceive('start')->once()->andReturn(new WorkflowInstance);
    app()->instance(WorkflowEngineInterface::class, $mock);
    app()->forgetInstance(InventoryCountService::class);
    app()->forgetInstance(InventoryCountServiceInterface::class);

    $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertOk()->assertJsonPath('status', 'pending_approval');

    expect(InventoryCountSession::query()->findOrFail($session['id'])->status)->toBe('pending_approval')
        ->and(wmsOnHand($this->bin, $item))->toBe('5.0000')
        ->and(fn () => wmsReceive($this->bin, $item, '1'))->toThrow(StockRuleViolation::class);
});

test('counts are validated, approval is a manager decision and other companies stay out', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->bin, $item, '2');
    $session = $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => now()->toDateString()])->json();
    $other = $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => now()->toDateString()])->json();
    $otherLine = InventoryCountLine::query()->where('inventory_count_session_id', $other['id'])->sole();

    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-count", ['session_line_id' => $otherLine->id, 'counted_quantity' => 1])->assertStatus(422);
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/recount", ['line_ids' => []])->assertStatus(422)->assertJsonValidationErrors('line_ids');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertStatus(422);
    $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $this->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => now()->toDateString(), 'freezes_stock' => 'maybe'])->assertStatus(422);

    $foreign = wmsInOtherTenant(static fn () => logisticsWarehouse());
    $this->postJson('/api/v1/inventory/counts', ['warehouse_id' => $foreign->id, 'count_type' => 'spot_check', 'scheduled_date' => now()->toDateString()])
        ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

    logisticsUser($this->tenant, 'warehouse');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertForbidden();
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/cancel")->assertForbidden();

    logisticsUser($this->tenant, 'accountant_readonly');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/recount", ['line_ids' => [(string) $otherLine->id]])->assertForbidden();
    $this->getJson("/api/v1/inventory/counts/{$session['id']}")->assertOk();

    logisticsUser($this->tenant);
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
});

test('the ABC classification ranks consumption and a cycle count plan counts its class', function (): void {
    $fast = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '10']);
    $slow = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);
    $idle = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);
    foreach ([$fast, $slow, $idle] as $item) {
        wmsReceive($this->bin, $item, '20');
    }
    logisticsMove($fast, $this->bin, null, 10);
    logisticsMove($slow, $this->bin, null, 5);

    $rows = collect($this->postJson('/api/v1/inventory/abc-classification/run', ['period_days' => 30, 'a_share' => 80, 'b_share' => 100])->assertOk()->json('data'))->keyBy('stock_item_id');

    expect($rows[$fast->id]['abc_class'])->toBe('A')
        ->and($rows[$slow->id]['abc_class'])->toBe('B')
        ->and($rows[$idle->id]['abc_class'])->toBe('C')
        ->and(StockItem::query()->findOrFail($fast->id)->abc_class)->toBe('A');

    $plan = $this->postJson('/api/v1/inventory/cycle-count-plans', ['warehouse_id' => $this->warehouse->id, 'name' => 'A mensile', 'abc_class' => 'A', 'interval_days' => 30])
        ->assertCreated()->json();

    $session = $this->postJson("/api/v1/inventory/cycle-count-plans/{$plan['id']}/run")->assertCreated()->json();

    expect(array_column($session['lines'], 'stock_item_id'))->toBe([$fast->id])
        ->and(CycleCountPlan::query()->findOrFail($plan['id'])->next_run_on->toDateString())->toBe(app(TenantClock::class)->today()->addDays(30)->toDateString());

    // The previous count of the plan is still open: no overlapping run.
    $this->postJson("/api/v1/inventory/cycle-count-plans/{$plan['id']}/run")->assertStatus(422);
    $this->postJson('/api/v1/inventory/cycle-count-plans/run-due')->assertOk()->assertJsonPath('opened', 0);
});

test('cycle count plans and ABC runs are manager decisions and validated', function (): void {
    $this->postJson('/api/v1/inventory/abc-classification/run', ['period_days' => 30, 'a_share' => 90, 'b_share' => 80])->assertStatus(422)->assertJsonValidationErrors('b_share');
    $this->postJson('/api/v1/inventory/cycle-count-plans', ['warehouse_id' => $this->warehouse->id, 'name' => 'X', 'interval_days' => 0])->assertStatus(422)->assertJsonValidationErrors('interval_days');

    $foreign = wmsInOtherTenant(static fn (): CycleCountPlan => CycleCountPlan::factory()->create(['tenant_id' => app(TenantContext::class)->getTenantId()]));
    $this->postJson("/api/v1/inventory/cycle-count-plans/{$foreign->id}/run")->assertNotFound();

    logisticsUser($this->tenant, 'warehouse');
    $this->postJson('/api/v1/inventory/abc-classification/run', ['period_days' => 30])->assertForbidden();
    $this->postJson('/api/v1/inventory/cycle-count-plans', ['warehouse_id' => $this->warehouse->id, 'name' => 'X', 'interval_days' => 7])->assertForbidden();
    $this->getJson('/api/v1/inventory/cycle-count-plans')->assertOk();
});
