<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgProductionOrder;

require_once __DIR__.'/MesTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mesSetUp($this);
    $this->operation = mesOperation($this);
});

/**
 * Sends events as the MES Core and returns the statuses in order.
 *
 * @param  list<array<string, mixed>>  $events
 * @return list<string>
 */
function mesFeedback(object $test, array $events): array
{
    $response = mesCall($test, 'POST', '/api/v1/manufacturing/mes-feedback/events', ['events' => $events])->assertStatus(207);

    return collect($response->json('results'))->pluck('status')->all();
}

test('an event id is applied once and the second delivery is a duplicate', function (): void {
    $event = mesSnapshotEvent($this, $this->operation, 1);

    expect(mesFeedback($this, [$event]))->toBe(['applied'])
        ->and(mesFeedback($this, [$event]))->toBe(['duplicate'])
        ->and(DB::table('mfg_mes_inbox')->count())->toBe(1)
        ->and(DB::table('mfg_mes_oee_snapshot_revisions')->count())->toBe(1);
});

test('an older revision that arrives late is stale and the latest snapshot stays', function (): void {
    $third = mesSnapshotEvent($this, $this->operation, 3, ['metrics' => ['run_ms' => 1800000, 'good_count' => 30]]);
    $second = mesSnapshotEvent($this, $this->operation, 2, ['metrics' => ['run_ms' => 600000, 'good_count' => 10]]);

    expect(mesFeedback($this, [$third, $second]))->toBe(['applied', 'stale']);

    $latest = DB::table('mfg_mes_oee_snapshots')->first();
    expect((int) $latest->revision)->toBe(3)
        ->and(json_decode((string) $latest->metrics, true)['run_ms'])->toBe(1800000)
        ->and(DB::table('mfg_mes_oee_snapshot_revisions')->count())->toBe(1)
        // The stale event is remembered, so redelivering it is a duplicate, not a second evaluation.
        ->and(mesFeedback($this, [$second]))->toBe(['duplicate']);
});

test('a higher revision replaces the snapshot and keeps every revision in the audit log', function (): void {
    expect(mesFeedback($this, [mesSnapshotEvent($this, $this->operation, 1), mesSnapshotEvent($this, $this->operation, 2)]))->toBe(['applied', 'applied'])
        ->and(DB::table('mfg_mes_oee_snapshots')->count())->toBe(1)
        ->and(DB::table('mfg_mes_oee_snapshot_revisions')->orderBy('revision')->pluck('revision')->map(fn ($revision): int => (int) $revision)->all())->toBe([1, 2]);
});

test('machine time is posted as deltas only when the tenant takes it from the MES', function (): void {
    mesFeedback($this, [mesSnapshotEvent($this, $this->operation, 1)]);
    expect(DB::table('mfg_mes_machine_time_postings')->count())->toBe(0);

    mesUseMachineTime($this);
    $operation = mesOperation($this);

    // Revision 1: 60 run, 10 setup minutes. Revision 2 corrects to 90 run, 10 setup, 5 downtime (alarm + stop).
    mesFeedback($this, [mesSnapshotEvent($this, $operation, 1)]);
    mesFeedback($this, [mesSnapshotEvent($this, $operation, 2, ['metrics' => ['run_ms' => 5400000, 'setup_ms' => 600000, 'alarm_ms' => 120000, 'down_ms' => 180000, 'good_count' => 140, 'scrap_count' => 3]])]);

    $postings = DB::table('mfg_mes_machine_time_postings')->where('production_order_operation_id', $operation->id)->orderBy('revision')->get();

    expect($postings)->toHaveCount(2)
        ->and((float) $postings[0]->delta_run_minutes)->toBe(60.0)
        ->and((float) $postings[0]->delta_setup_minutes)->toBe(10.0)
        ->and($postings[0]->reason)->toBe('initial')
        ->and((float) $postings[0]->delta_machine_cost)->toBe(60.0)
        ->and((float) $postings[1]->delta_run_minutes)->toBe(30.0)
        ->and((float) $postings[1]->delta_setup_minutes)->toBe(0.0)
        ->and((float) $postings[1]->delta_downtime_minutes)->toBe(5.0)
        ->and($postings[1]->reason)->toBe('late_revision')
        ->and((float) $postings[1]->delta_machine_cost)->toBe(30.0)
        ->and((float) $postings->sum('delta_run_minutes'))->toBe(90.0);
});

test('a revision that changes nothing posts no delta', function (): void {
    mesUseMachineTime($this);

    mesFeedback($this, [mesSnapshotEvent($this, $this->operation, 1), mesSnapshotEvent($this, $this->operation, 2, ['metrics' => ['run_ms' => 3600000, 'setup_ms' => 600000, 'alarm_ms' => 0, 'down_ms' => 0, 'good_count' => 95, 'scrap_count' => 2]])]);

    expect(DB::table('mfg_mes_machine_time_postings')->count())->toBe(1);
});

test('counts become a proposal and never move the order or the stock quantities', function (): void {
    $order = MfgProductionOrder::query()->findOrFail($this->operation->production_order_id);
    $before = [(string) $order->completed_quantity, (string) $order->scrapped_quantity, (string) $this->operation->completed_quantity];
    $stockMovements = DB::table('stock_movements')->count();

    mesFeedback($this, [mesSnapshotEvent($this, $this->operation, 1)]);
    mesFeedback($this, [mesSnapshotEvent($this, $this->operation, 2, ['metrics' => ['run_ms' => 3600000, 'good_count' => 95, 'scrap_count' => 2]])]);
    mesFeedback($this, [[
        'event_id' => (string) Str::uuid7(), 'idempotency_key' => 'c1', 'event_type' => 'ProductionCountCheckpoint', 'schema_version' => 1,
        'tenant_id' => (string) $this->tenant->id, 'machine_id' => (string) $this->machine->id, 'production_order_operation_id' => (string) $this->operation->id,
        'as_of_event_time' => '2027-03-01T11:00:00Z', 'occurred_at' => '2027-03-01T11:00:00Z', 'metrics' => ['good_count' => 120, 'scrap_count' => 4],
    ]]);

    $proposals = DB::table('mfg_mes_count_proposals')->get();
    expect($proposals)->toHaveCount(1)
        ->and($proposals[0]->status)->toBe('pending')
        ->and((float) $proposals[0]->good_count)->toBe(120.0)
        ->and((float) $proposals[0]->scrap_count)->toBe(4.0);

    $order->refresh();
    $this->operation->refresh();
    expect([(string) $order->completed_quantity, (string) $order->scrapped_quantity, (string) $this->operation->completed_quantity])->toBe($before)
        ->and(DB::table('stock_movements')->count())->toBe($stockMovements);
});

test('malformed events and unknown references are rejected one by one with a problem document', function (): void {
    $unknownMachine = mesSnapshotEvent($this, $this->operation, 1, ['machine_id' => (string) Str::uuid7()]);
    $unknownOperation = mesSnapshotEvent($this, $this->operation, 1, ['production_order_operation_id' => (string) Str::uuid7()]);
    $malformed = ['event_id' => (string) Str::uuid7(), 'event_type' => 'OeeOrderCompleted'];
    $future = mesSnapshotEvent($this, $this->operation, 1, ['schema_version' => 99]);
    $ok = mesSnapshotEvent($this, $this->operation, 1);

    $response = mesCall($this, 'POST', '/api/v1/manufacturing/mes-feedback/events', ['events' => [$unknownMachine, $unknownOperation, $malformed, $future, $ok]])->assertStatus(207);

    expect($response->json('results.*.status'))->toBe(['rejected', 'rejected', 'rejected', 'rejected', 'applied'])
        ->and($response->json('results.0.problem.code'))->toBe('mes.machine.unknown')
        ->and($response->json('results.1.problem.code'))->toBe('mes.operation.unknown')
        ->and($response->json('results.2.problem.code'))->toBe('mes.event.invalid')
        ->and($response->json('results.2.problem.errors'))->not->toBeEmpty()
        ->and($response->json('results.3.problem.code'))->toBe('mes.event.unsupported_schema');

    // A rejected event leaves no trace in the inbox, so the MES Core can resend it corrected.
    expect(DB::table('mfg_mes_inbox')->count())->toBe(1);
});

test('the envelope needs at least one event', function (): void {
    mesCall($this, 'POST', '/api/v1/manufacturing/mes-feedback/events', ['events' => []])->assertUnprocessable();
});
