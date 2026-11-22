<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Database\Seeders\MesReasonCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Manufacturing\Events\MesWorkOrderAssignedToMachineEvent;
use Modules\Manufacturing\Events\MesWorkOrderClosedEvent;
use Modules\Manufacturing\Events\MesWorkOrderReleasedEvent;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgReasonCode;
use Modules\Manufacturing\Models\MfgReasonCodeLabel;
use Modules\Manufacturing\Support\IdealCycleTime;

require_once __DIR__.'/MesTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mesSetUp($this);
});

/**
 * @return array<string, mixed>
 */
function mesPull(object $test, string $resource, string $query = ''): array
{
    return mesCall($test, 'GET', "/api/v1/manufacturing/mes-sync/{$resource}{$query}")->assertOk()->json();
}

test('changes get a monotonic sequence and a page is cut with limit plus one', function (): void {
    $extra = MfgMachineCenter::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id]);

    $seqs = DB::table('mfg_mes_sync_changes')->where('resource', 'machines')->orderBy('seq')->pluck('seq')->map(fn ($seq): int => (int) $seq)->all();
    expect($seqs)->toHaveCount(3)->and($seqs)->toBe(collect($seqs)->sort()->values()->all());

    $first = mesPull($this, 'machines', '?limit=2');
    expect($first['data'])->toHaveCount(2)->and($first['has_more'])->toBeTrue()->and($first['watermark'])->toBe($seqs[2])
        ->and($first['data'][0]['deleted'])->toBeFalse()->and($first['data'][0]['payload']['machine_id'] ?? $first['data'][0]['payload']['id'] ?? null)->not->toBeNull();

    $second = mesPull($this, 'machines', '?limit=2&after_seq='.$first['next_seq']);
    expect($second['data'])->toHaveCount(1)->and($second['has_more'])->toBeFalse()
        ->and(collect([...$first['data'], ...$second['data']])->pluck('id')->unique()->count())->toBe(3);

    $empty = mesPull($this, 'machines', '?after_seq='.$second['next_seq']);
    expect($empty['data'])->toBe([])->and($empty['has_more'])->toBeFalse();

    $extra->first()->delete();
    $tombstone = mesPull($this, 'machines', '?after_seq='.$second['next_seq']);
    expect($tombstone['data'])->toHaveCount(1)->and($tombstone['data'][0]['deleted'])->toBeTrue()->and($tombstone['data'][0]['payload'])->toBe([]);
});

test('planned orders are not exposed until they are released', function (): void {
    Event::fake([MesWorkOrderReleasedEvent::class, MesWorkOrderAssignedToMachineEvent::class, MesWorkOrderClosedEvent::class]);

    $operation = mesOperation($this, 'planned');
    expect(DB::table('mfg_mes_sync_changes')->where('resource', 'work-orders')->count())->toBe(0)
        ->and(mesPull($this, 'work-orders')['data'])->toBe([]);

    MfgProductionOrder::query()->findOrFail($operation->production_order_id)->update(['status' => 'released']);

    $page = mesPull($this, 'work-orders');
    expect($page['data'])->toHaveCount(1)
        ->and($page['data'][0]['id'])->toBe((string) $operation->id)
        ->and($page['data'][0]['payload']['status'])->toBe('Released')
        ->and($page['data'][0]['payload']['machine_id'])->toBe((string) $this->machine->id);
    Event::assertDispatched(MesWorkOrderReleasedEvent::class);

    MfgProductionOrder::query()->findOrFail($operation->production_order_id)->update(['status' => 'closed']);
    Event::assertDispatched(MesWorkOrderClosedEvent::class);
    expect(mesPull($this, 'work-orders')['data'][0]['payload']['status'])->toBe('Completed');
});

test('the ideal cycle time is explicit when frozen on the operation and derived from the planned run time otherwise', function (): void {
    $derived = mesOperation($this, 'released', ['target_quantity' => '120'], ['planned_run_minutes' => '60']);
    $explicit = mesOperation($this, 'released', ['target_quantity' => '120'], ['planned_run_minutes' => '60', 'ideal_cycle_time_ms' => '25000.000']);

    $items = collect(mesPull($this, 'work-orders')['data'])->keyBy('id');

    // 60 min * 60000 / 120 pieces = 30000 ms per piece.
    expect((float) $items[(string) $derived->id]['payload']['ideal_cycle_time_ms'])->toBe(30000.0)
        ->and($items[(string) $derived->id]['payload']['ict_source'])->toBe(IdealCycleTime::Derived)
        ->and((float) $items[(string) $explicit->id]['payload']['ideal_cycle_time_ms'])->toBe(25000.0)
        ->and($items[(string) $explicit->id]['payload']['ict_source'])->toBe(IdealCycleTime::Explicit);
});

test('moving an operation to another machine is notified and a stale cursor is gone', function (): void {
    Event::fake([MesWorkOrderAssignedToMachineEvent::class]);

    $operation = mesOperation($this);
    $other = MfgMachineCenter::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id]);
    MfgProductionOrderOperation::query()->findOrFail($operation->id)->update(['machine_center_id' => $other->id]);
    Event::assertDispatched(MesWorkOrderAssignedToMachineEvent::class);

    $page = mesPull($this, 'work-orders');
    expect($page['data'][0]['payload']['machine_id'])->toBe((string) $other->id);

    // Pruned part of the log: a sequence below it can no longer be served.
    DB::table('mfg_mes_sync_floors')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'pruned_through_seq' => 1000000, 'created_at' => now(), 'updated_at' => now()]);
    $gone = mesCall($this, 'GET', '/api/v1/manufacturing/mes-sync/work-orders?after_seq='.$page['next_seq'])->assertStatus(410);
    expect($gone->json('code'))->toBe('mes.sync.cursor_gone')->and($gone->headers->get('Content-Type'))->toContain('application/problem+json');

    mesCall($this, 'GET', '/api/v1/manufacturing/mes-sync/work-orders?after_seq=not-a-number')->assertStatus(422)->assertJsonPath('code', 'mes.request.invalid');
});

test('updated_since bootstraps the first call from a point in time', function (): void {
    DB::table('mfg_mes_sync_changes')->delete();
    $this->travelTo(now()->subDays(2));
    MfgMachineCenter::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id]);
    $this->travelBack();

    $recent = MfgMachineCenter::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id]);

    $page = mesPull($this, 'machines', '?updated_since='.urlencode(now()->subHour()->toIso8601String()));
    expect($page['data'])->toHaveCount(1)->and($page['data'][0]['id'])->toBe((string) $recent->id);

    expect(mesPull($this, 'machines', '?updated_since='.urlencode(now()->addDay()->toIso8601String()))['data'])->toBe([]);
});

test('the manifest lists id and revision pairs of the live entities only', function (): void {
    $machine2 = MfgMachineCenter::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_id' => $this->workCenter->id]);
    $machine2->delete();

    $manifest = collect(mesPull($this, 'machines/manifest')['items'])->keyBy('id');

    expect($manifest)->toHaveCount(1)
        ->and($manifest->keys()->all())->toBe([(string) $this->machine->id])
        ->and($manifest[(string) $this->machine->id]['revision'])->toBeInt()
        ->and($manifest->has((string) $machine2->id))->toBeFalse();
});

test('reason codes are served with their labels and a label change is a change of the code', function (): void {
    $code = MfgReasonCode::create(['tenant_id' => $this->tenant->id, 'code' => 'JAM', 'category' => 'UnplannedDowntime', 'classification' => 'AvailabilityLoss', 'is_active' => true]);
    MfgReasonCodeLabel::create(['tenant_id' => $this->tenant->id, 'reason_code_id' => $code->id, 'locale' => 'en', 'label' => 'Jam']);

    $before = mesPull($this, 'reason-codes');
    expect($before['data'])->toHaveCount(1)->and($before['data'][0]['payload']['labels'])->toBe(['en' => 'Jam']);

    MfgReasonCodeLabel::create(['tenant_id' => $this->tenant->id, 'reason_code_id' => $code->id, 'locale' => 'it', 'label' => 'Inceppamento']);

    $after = mesPull($this, 'reason-codes', '?after_seq='.$before['next_seq']);
    expect($after['data'])->toHaveCount(1)->and($after['data'][0]['payload']['labels'])->toBe(['en' => 'Jam', 'it' => 'Inceppamento'])
        ->and($after['data'][0]['revision'])->toBeGreaterThan($before['data'][0]['revision']);
});

test('the default reason codes seed once per company with the four locales', function (): void {
    $this->seed(MesReasonCodeSeeder::class);
    $this->seed(MesReasonCodeSeeder::class);

    $codes = DB::table('mfg_reason_codes')->where('tenant_id', $this->tenant->id)->count();
    expect($codes)->toBe(count(MesReasonCodeSeeder::Codes))
        ->and(DB::table('mfg_reason_code_labels')->where('tenant_id', $this->tenant->id)->count())->toBe($codes * 4)
        ->and(DB::table('mfg_reason_code_labels')->where('tenant_id', $this->otherTenant->id)->pluck('locale')->unique()->sort()->values()->all())->toBe(['de', 'en', 'fr', 'it']);
});

test('one tenant never sees the changes of another', function (): void {
    app(TenantContext::class)->runWithTenant($this->otherTenant, fn () => MfgMachineCenter::factory()->create(['tenant_id' => $this->otherTenant->id]));

    expect(mesPull($this, 'machines')['data'])->toHaveCount(1);
});
