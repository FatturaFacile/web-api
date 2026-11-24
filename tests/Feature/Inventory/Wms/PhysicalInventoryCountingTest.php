<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Listeners\InventoryAccountingListener;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\CycleCountPlan;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountEntry;
use Modules\Inventory\Models\InventoryCountLine;
use Modules\Inventory\Models\InventoryCountSession;
use Modules\Inventory\Models\StockItem;

require_once __DIR__.'/wms_fixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = logisticsTenant();
    $this->user = logisticsUser($this->tenant);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, (int) now()->year);
    $this->warehouse = logisticsWarehouse();
    $this->bin = logisticsBin($this->warehouse);
});

function openCount(array $extra = []): array
{
    return test()->postJson('/api/v1/inventory/counts', $extra + [
        'warehouse_id' => test()->warehouse->id, 'count_type' => 'spot_check', 'scheduled_date' => now()->toDateString(),
    ])->assertCreated()->json();
}

function countLineOf(array $session): InventoryCountLine
{
    return InventoryCountLine::query()->where('inventory_count_session_id', $session['id'])->sole();
}

function recordLine(array $session, InventoryCountLine $line, string $quantity): TestResponse
{
    return test()->postJson("/api/v1/inventory/counts/{$session['id']}/record-count", ['session_line_id' => $line->id, 'counted_quantity' => $quantity]);
}

function setPolicy(array $policy): void
{
    test()->putJson('/api/v1/inventory/count-policy', $policy)->assertOk();
}

test('a blind session never exposes the system quantity until the variance view of a manager', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '4']);
    wmsReceive($this->bin, $item, '10');
    $session = openCount();

    expect($session['is_blind'])->toBeTrue();

    $sheet = $this->getJson("/api/v1/inventory/counts/{$session['id']}/sheet")->assertOk();
    $shown = $this->getJson("/api/v1/inventory/counts/{$session['id']}")->assertOk();

    foreach ([$sheet->json('data.0'), $shown->json('lines.0')] as $line) {
        expect($line)->not->toHaveKeys(['system_quantity', 'unit_cost', 'system_value', 'variance_quantity', 'variance_value', 'variance_percentage', 'first_counted_quantity'])
            ->and($line['sku'])->toBe($item->sku);
    }

    $recorded = recordLine($session, countLineOf($session), '7')->assertOk();
    expect($recorded->json())->not->toHaveKeys(['system_quantity', 'discrepancy_quantity', 'variance_quantity']);

    $variance = $this->getJson("/api/v1/inventory/counts/{$session['id']}/variance")->assertOk();
    expect($variance->json('lines.0.system_quantity'))->toBe('10.0000')
        ->and($variance->json('lines.0.final_quantity'))->toBe('7.0000')
        ->and($variance->json('lines.0.variance_quantity'))->toBe('-3.0000')
        ->and($variance->json('lines.0.variance_value'))->toBe('-12.00')
        ->and($variance->json('lines.0.variance_percentage'))->toBe('30.00')
        ->and($variance->json('summary.gross_variance_value'))->toBe('12.00');

    logisticsUser($this->tenant, 'warehouse');
    $this->getJson("/api/v1/inventory/counts/{$session['id']}/variance")->assertForbidden();
});

test('a session can be opened as a non-blind sheet', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->bin, $item, '3');
    $session = openCount(['is_blind' => false]);

    expect($this->getJson("/api/v1/inventory/counts/{$session['id']}/sheet")->json('data.0.system_quantity'))->toBe('3.0000');
});

test('a variance above the recount threshold sends the line back blind, until the rounds are used up', function (): void {
    setPolicy(['recount_threshold_percent' => 10, 'max_recount_rounds' => 1]);
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);
    wmsReceive($this->bin, $item, '10');
    $session = openCount();
    $line = countLineOf($session);

    recordLine($session, $line, '5')->assertOk()->assertJsonPath('recount_required', true)->assertJsonPath('recount_round', 1);

    $line->refresh();
    expect($line->counted_quantity)->toBeNull()
        ->and((string) $line->first_counted_quantity)->toBe('5.0000')
        ->and($line->recount_required)->toBeTrue()
        ->and($line->recount_reason)->toBe('variance');

    // The recount sheet does not show the first count (policy does not reveal it).
    expect($this->getJson("/api/v1/inventory/counts/{$session['id']}/sheet?pending=1")->json('data.0'))->not->toHaveKeys(['first_counted_quantity', 'system_quantity'])
        ->and($this->getJson("/api/v1/inventory/counts/{$session['id']}/sheet?pending=1")->json('data.0.recount_required'))->toBeTrue();

    // Approving with a recount pending is refused.
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertStatus(422);

    // The only round is used: a second wide variance is accepted as the final count.
    recordLine($session, $line, '4')->assertOk()->assertJsonPath('recount_required', false);

    expect((string) $line->fresh()->counted_quantity)->toBe('4.0000')
        ->and(InventoryCountEntry::query()->where('inventory_count_line_id', $line->id)->orderBy('round')->pluck('round')->all())->toBe([0, 1]);
});

test('counts sent in a batch are idempotent by client entry id and report rejected entries', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->bin, $item, '10');
    $session = openCount();
    $line = countLineOf($session);
    $entryId = (string) Str::uuid();
    $payload = ['device_id' => 'handheld-1', 'entries' => [['client_entry_id' => $entryId, 'session_line_id' => $line->id, 'counted_quantity' => 9]]];

    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-counts", $payload)->assertOk()->assertJsonPath('data.0.status', 'recorded');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-counts", $payload)->assertOk()->assertJsonPath('data.0.status', 'recorded');

    expect(InventoryCountEntry::query()->where('client_entry_id', $entryId)->count())->toBe(1);

    $other = openCount();
    $foreignLine = countLineOf($other);
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/record-counts", ['entries' => [['client_entry_id' => (string) Str::uuid(), 'session_line_id' => $foreignLine->id, 'counted_quantity' => 1]]])
        ->assertOk()->assertJsonPath('data.0.status', 'rejected');
});

test('two approval levels: segregation of duties, role checks, sequence and the posting of stock and ledger', function (): void {
    setPolicy(['approval_mode' => 'multi_level', 'level1_threshold_value' => 1, 'level2_threshold_value' => 50]);
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '10']);
    wmsReceive($this->bin, $item, '10');
    $session = openCount(['freezes_stock' => true]);
    recordLine($session, countLineOf($session), '2')->assertOk();

    // Variance 8 x 10 = 80 > 50: both levels.
    $submitter = $this->user;
    $adjustment = $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertOk()->assertJsonPath('status', 'pending_approval')->json();
    $shown = $this->getJson("/api/v1/inventory/counts/{$session['id']}")->assertOk();
    expect($shown->json('status'))->toBe('pending_approval')
        ->and($shown->json('approval_level_required'))->toBe(2)
        ->and(wmsOnHand($this->bin, $item))->toBe('10.0000');

    // The submitter cannot approve; level 2 cannot go first.
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/1/approve")->assertStatus(422);
    $manager = logisticsUser($this->tenant, 'admin');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/2/approve")->assertForbidden();
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/1/approve", ['notes' => 'checked on the shelf'])->assertOk()->assertJsonPath('status', 'pending_approval');

    // Level 2 is the finance approver, a different person from level 1 and from the submitter.
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/2/approve")->assertForbidden();
    $finance = logisticsUser($this->tenant, 'owner');
    expect($finance->id)->not->toBe($manager->id);
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/2/approve")->assertOk()->assertJsonPath('status', 'reconciled');

    expect(wmsOnHand($this->bin, $item))->toBe('2.0000')
        ->and(InventoryCountSession::query()->findOrFail($session['id'])->status)->toBe('reconciled');

    // The adjustment and its journal entry point at each other.
    $entry = JournalEntry::query()->where('source_document_type', 'inventory_adjustment')->where('source_document_id', $adjustment['id'])->sole();
    $stored = InventoryAdjustment::query()->findOrFail($adjustment['id']);
    expect((string) $stored->journal_entry_id)->toBe((string) $entry->id)
        ->and($stored->status)->toBe('approved')
        ->and((float) $entry->total_amount)->toBe(80.0);

    $variance = $this->getJson("/api/v1/inventory/counts/{$session['id']}/variance")->assertOk();
    expect($variance->json('approvals'))->toHaveCount(2)
        ->and($variance->json('approvals.0.status'))->toBe('approved')
        ->and($variance->json('adjustment.journal_entry_id'))->toBe((string) $entry->id);

    // The freeze ended with the approval.
    wmsReceive($this->bin, $item, '1');
    Sanctum::actingAs($submitter);
});

test('a rejection needs a reason, undoes nothing in stock and puts the count back in progress', function (): void {
    setPolicy(['approval_mode' => 'multi_level', 'level1_threshold_value' => 1, 'level2_threshold_value' => 5000]);
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '10']);
    wmsReceive($this->bin, $item, '10');
    $session = openCount(['freezes_stock' => true]);
    recordLine($session, countLineOf($session), '8')->assertOk();
    $adjustment = $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertOk()->json();

    logisticsUser($this->tenant, 'admin');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/1/reject")->assertStatus(422)->assertJsonValidationErrors('reason');
    // The adjustment endpoints cannot bypass the levels.
    $this->postJson("/api/v1/inventory/adjustments/{$adjustment['id']}/approve")->assertStatus(422);
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/approvals/1/reject", ['reason' => 'shelf not fully counted'])->assertOk()->assertJsonPath('status', 'in_progress');

    expect(wmsOnHand($this->bin, $item))->toBe('10.0000')
        ->and(InventoryAdjustment::query()->findOrFail($adjustment['id'])->status)->toBe('rejected')
        ->and(JournalEntry::query()->where('source_document_id', $adjustment['id'])->count())->toBe(0)
        ->and(fn () => wmsReceive($this->bin, $item, '1'))->toThrow(StockRuleViolation::class);

    $events = $this->getJson("/api/v1/inventory/counts/{$session['id']}/events")->assertOk()->json('data');
    expect(collect($events)->pluck('event')->all())->toContain('differences_submitted', 'level_rejected');
});

test('differences within tolerance are posted at once and gains and losses use the configured accounts', function (): void {
    setPolicy(['approval_mode' => 'multi_level', 'level1_threshold_value' => 1000, 'gain_account_code' => '4920', 'loss_account_code' => '5150']);
    $gain = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '2']);
    $loss = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '3']);
    wmsReceive($this->bin, $gain, '5');
    wmsReceive($this->bin, $loss, '5');

    $session = openCount();
    foreach (InventoryCountLine::query()->where('inventory_count_session_id', $session['id'])->get() as $line) {
        recordLine($session, $line, $line->stock_item_id === $gain->id ? '8' : '4')->assertOk();
    }

    $adjustment = $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertOk()->assertJsonPath('status', 'approved')->json();

    expect(InventoryCountSession::query()->findOrFail($session['id'])->status)->toBe('reconciled')
        ->and(wmsOnHand($this->bin, $gain))->toBe('8.0000')
        ->and(wmsOnHand($this->bin, $loss))->toBe('4.0000');

    // Net +6 -3 = +3: one entry, inventory debit against the configured gain account.
    $entry = JournalEntry::query()->where('source_document_type', 'inventory_adjustment')->where('source_document_id', $adjustment['id'])->sole();
    expect((float) $entry->total_amount)->toBe(3.0)
        ->and(ChartOfAccount::query()->where('account_code', '4920')->exists())->toBeTrue();
});

test('the ledger posting of an adjustment is idempotent', function (): void {
    $item = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '2']);
    wmsReceive($this->bin, $item, '5');
    $session = openCount();
    recordLine($session, countLineOf($session), '3')->assertOk();
    $adjustment = $this->postJson("/api/v1/inventory/counts/{$session['id']}/reconcile")->assertOk()->json();

    $model = InventoryAdjustment::query()->findOrFail($adjustment['id']);
    $event = InventoryAdjustmentPostedEvent::forAdjustment($model, [['stock_item_id' => (string) $item->id, 'quantity_delta' => '-2', 'unit_cost' => '2']]);
    app(InventoryAccountingListener::class)->handleAdjustmentPosted($event);
    app(InventoryAccountingListener::class)->handleAdjustmentPosted($event);

    expect(JournalEntry::query()->where('source_document_type', 'inventory_adjustment')->where('source_document_id', $adjustment['id'])->count())->toBe(1)
        ->and($model->fresh()->journal_entry_id)->not->toBeNull();
});

test('an ABC run is stored with its ranked items, manual overrides are audited and survive later runs', function (): void {
    $fast = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '10']);
    $slow = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);
    $idle = logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => '1']);
    foreach ([$fast, $slow, $idle] as $item) {
        wmsReceive($this->bin, $item, '20');
    }
    logisticsMove($fast, $this->bin, null, 10);
    logisticsMove($slow, $this->bin, null, 5);

    $run = $this->postJson('/api/v1/inventory/abc-runs', ['period_days' => 30, 'a_share' => 80, 'b_share' => 100])->assertCreated();
    $items = collect($run->json('items'))->keyBy('stock_item_id');

    expect($run->json('item_count'))->toBe(3)
        ->and($run->json('class_a_count'))->toBe(1)
        ->and($items[$fast->id]['abc_class'])->toBe('A')
        ->and($items[$fast->id]['rank'])->toBe(1)
        ->and($items[$slow->id]['abc_class'])->toBe('B')
        ->and($items[$idle->id]['abc_class'])->toBe('C')
        ->and(StockItem::query()->findOrFail($slow->id)->abc_class)->toBe('B');

    $this->getJson("/api/v1/inventory/abc-runs/{$run->json('id')}")->assertOk()->assertJsonCount(3, 'items');
    $this->getJson('/api/v1/inventory/abc-runs')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson('/api/v1/inventory/abc-overrides', ['stock_item_id' => $slow->id, 'abc_class' => 'A'])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson('/api/v1/inventory/abc-overrides', ['stock_item_id' => $slow->id, 'abc_class' => 'A', 'reason' => 'critical spare part'])->assertCreated()->assertJsonPath('previous_class', 'B');

    $second = $this->postJson('/api/v1/inventory/abc-runs', ['period_days' => 30, 'a_share' => 80, 'b_share' => 100])->assertCreated();
    $slowRow = collect($second->json('items'))->firstWhere('stock_item_id', $slow->id);

    expect($slowRow['abc_class'])->toBe('A')
        ->and($slowRow['is_manual_override'])->toBeTrue()
        ->and(StockItem::query()->findOrFail($slow->id)->abc_class)->toBe('A');

    // A run that is not applied leaves the classes of the items alone.
    $this->postJson('/api/v1/inventory/abc-overrides', ['stock_item_id' => $slow->id, 'abc_class' => null, 'reason' => 'back to automatic'])->assertCreated();
    $this->postJson('/api/v1/inventory/abc-runs', ['period_days' => 30, 'a_share' => 50, 'b_share' => 60, 'apply' => false])->assertCreated()->assertJsonPath('applied', false);
    expect(StockItem::query()->findOrFail($slow->id)->abc_class)->toBe('A');
    $this->getJson('/api/v1/inventory/abc-overrides')->assertOk()->assertJsonCount(2, 'data');
});

test('cycle count plans are generated per class with the cadences of the policy', function (): void {
    setPolicy(['interval_days_a' => 14]);

    $plans = $this->postJson('/api/v1/inventory/cycle-count-plans/generate', ['warehouse_id' => $this->warehouse->id])->assertCreated()->json('data');
    expect(collect($plans)->pluck('interval_days', 'abc_class')->all())->toBe(['A' => 14, 'B' => 90, 'C' => 180])
        ->and(collect($plans)->pluck('cadence', 'abc_class')->all())->toBe(['A' => 'custom', 'B' => 'quarterly', 'C' => 'semi_annual']);

    setPolicy(['interval_days_a' => 30]);
    $again = $this->postJson('/api/v1/inventory/cycle-count-plans/generate', ['warehouse_id' => $this->warehouse->id])->assertCreated()->json('data');

    expect(CycleCountPlan::query()->count())->toBe(3)
        ->and(collect($again)->firstWhere('abc_class', 'A')['cadence'])->toBe('monthly');
});

test('a freeze limited to bins blocks only those bins, and releasing it needs a reason and is audited', function (): void {
    $item = logisticsItem(['tracking_type' => 'none']);
    $other = logisticsBin($this->warehouse, 'storage', 'A1', 'R1', 'B2');
    wmsReceive($this->bin, $item, '5');
    wmsReceive($other, $item, '5');

    $session = openCount(['freezes_stock' => true, 'bin_ids' => [(string) $this->bin->id]]);

    expect($session['freeze_scope'])->toBe('bins')
        ->and(fn () => wmsReceive($this->bin, $item, '1'))->toThrow(StockRuleViolation::class);
    wmsReceive($other, $item, '1');
    expect(wmsOnHand($other, $item))->toBe('6.0000');

    $this->postJson("/api/v1/inventory/counts/{$session['id']}/unfreeze")->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/unfreeze", ['reason' => 'urgent customer delivery'])->assertOk()->assertJsonPath('freezes_stock', false);
    wmsReceive($this->bin, $item, '1');
    $this->postJson("/api/v1/inventory/counts/{$session['id']}/freeze")->assertOk()->assertJsonPath('freezes_stock', true);

    $events = collect($this->getJson("/api/v1/inventory/counts/{$session['id']}/events")->json('data'));
    expect($events->firstWhere('event', 'stock_unfrozen')['payload']['reason'])->toBe('urgent customer delivery');
});

test('the counting policy and the sessions of another company stay out of reach', function (): void {
    setPolicy(['recount_threshold_percent' => 7]);
    $item = logisticsItem(['tracking_type' => 'none']);
    wmsReceive($this->bin, $item, '2');
    $session = openCount();

    $this->getJson('/api/v1/inventory/count-policy')->assertOk()->assertJsonPath('recount_threshold_percent', '7.00')->assertJsonPath('is_default', false);

    $other = logisticsTenant('Altra Logistica');
    logisticsUser($other);
    $this->getJson("/api/v1/inventory/counts/{$session['id']}/sheet")->assertNotFound();
    $this->getJson('/api/v1/inventory/count-policy')->assertOk()->assertJsonPath('is_default', true)->assertJsonPath('recount_threshold_percent', null);

    // Segregated roles: counters do not manage the policy.
    app(TenantContext::class)->setTenant($this->tenant);
    logisticsUser($this->tenant, 'warehouse');
    $this->putJson('/api/v1/inventory/count-policy', ['recount_threshold_percent' => 1])->assertForbidden();
});

test('the policy refuses inconsistent shares and thresholds', function (): void {
    $this->putJson('/api/v1/inventory/count-policy', ['abc_a_share' => 95, 'abc_b_share' => 90])->assertStatus(422)->assertJsonValidationErrors('abc_b_share');
    $this->putJson('/api/v1/inventory/count-policy', ['level1_threshold_value' => 500, 'level2_threshold_value' => 100])->assertStatus(422)->assertJsonValidationErrors('level2_threshold_value');
});
