<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\Facility\Models\FacilityServiceRequest;

require_once __DIR__.'/FacilityTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 08:00:00');
    $this->tenant = facTenant();
    $this->technician = facMember($this->tenant, 'technician');
    $this->requester = facMember($this->tenant, 'member');
    $this->colleague = facMember($this->tenant, 'member');
    $this->building = facBuilding($this->tenant);
    $this->room = facSpace($this->tenant, 'meeting_room', ['building_id' => $this->building->id]);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @return array<string, mixed>
 */
function facRequestPayload(array $extra = []): array
{
    return ['category' => 'cleaning', 'title' => 'Sala riunioni sporca', 'description' => 'Dopo l\'evento', ...$extra];
}

test('a member raises a request that gets a number and an SLA due date by priority', function (): void {
    facAct($this->requester, $this->tenant);
    $normal = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['space_id' => $this->room->id]))
        ->assertCreated()
        ->assertJsonPath('data.number', 'SR-2027-0001')
        ->assertJsonPath('data.status', 'new')
        ->assertJsonPath('data.priority', 'normal')
        ->assertJsonPath('data.building_id', $this->building->id)
        ->assertJsonPath('data.due_at', '2027-05-13T08:00:00+00:00')
        ->assertJsonPath('data.requester.id', $this->requester->id)
        ->json('data');

    $urgent = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['category' => 'hvac', 'priority' => 'urgent', 'title' => 'Niente aria condizionata']))
        ->assertCreated()->assertJsonPath('data.number', 'SR-2027-0002')->assertJsonPath('data.due_at', '2027-05-12T12:00:00+00:00')->json('data');

    $this->putJson("/api/v1/facility/service-requests/{$normal['id']}", facRequestPayload(['priority' => 'high']))
        ->assertOk()->assertJsonPath('data.due_at', '2027-05-12T16:00:00+00:00');

    $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['space_id' => $this->room->id, 'building_id' => facBuilding($this->tenant)->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['building_id']);

    expect($urgent['id'])->not->toBe($normal['id']);
});

test('members see their own requests and operators see all of them', function (): void {
    facAct($this->requester, $this->tenant);
    $mine = $this->postJson('/api/v1/facility/service-requests', facRequestPayload())->assertCreated()->json('data');
    facAct($this->colleague, $this->tenant);
    $theirs = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['title' => 'Lampada rotta', 'category' => 'repair']))->assertCreated()->json('data');

    $this->getJson('/api/v1/facility/service-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $theirs['id']);
    $this->getJson("/api/v1/facility/service-requests/{$mine['id']}")->assertForbidden();
    $this->getJson('/api/v1/facility/service-requests/board')->assertOk()->assertJsonPath('data.open', 1);

    facAct($this->technician, $this->tenant);
    $this->getJson('/api/v1/facility/service-requests')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/facility/service-requests?category=repair&open=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/facility/service-requests/board')->assertOk()->assertJsonPath('data.open', 2)->assertJsonPath('data.by_status.new', 2);
});

test('the status flow is validated and only operators work a request', function (): void {
    facAct($this->requester, $this->tenant);
    $request = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['category' => 'repair']))->assertCreated()->json('data');
    $id = $request['id'];

    $this->postJson("/api/v1/facility/service-requests/{$id}/assign", ['assignee_id' => $this->technician->id])->assertForbidden();
    $this->postJson("/api/v1/facility/service-requests/{$id}/start")->assertForbidden();

    facAct($this->technician, $this->tenant);
    $this->postJson("/api/v1/facility/service-requests/{$id}/close")->assertUnprocessable();
    $this->postJson("/api/v1/facility/service-requests/{$id}/resolve", ['resolution_notes' => 'Fatto'])->assertUnprocessable();
    $this->postJson("/api/v1/facility/service-requests/{$id}/assign", ['assignee_id' => $this->technician->id])
        ->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assignee.id', $this->technician->id);
    $this->postJson("/api/v1/facility/service-requests/{$id}/hold", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("/api/v1/facility/service-requests/{$id}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/facility/service-requests/{$id}/hold", ['reason' => 'Attesa ricambio'])->assertOk()->assertJsonPath('data.status', 'on_hold')->assertJsonPath('data.hold_reason', 'Attesa ricambio');
    $this->postJson("/api/v1/facility/service-requests/{$id}/resolve", ['resolution_notes' => 'Fatto'])->assertUnprocessable();
    $this->postJson("/api/v1/facility/service-requests/{$id}/start")->assertOk()->assertJsonPath('data.hold_reason', null);
    $this->postJson("/api/v1/facility/service-requests/{$id}/resolve", ['resolution_notes' => 'Sostituita la lampada'])
        ->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.is_overdue', false);

    facAct($this->requester, $this->tenant);
    $this->postJson("/api/v1/facility/service-requests/{$id}/reopen")->assertOk()->assertJsonPath('data.status', 'in_progress');

    facAct($this->technician, $this->tenant);
    $this->postJson("/api/v1/facility/service-requests/{$id}/resolve", ['resolution_notes' => 'Risolto davvero'])->assertOk();
    $this->postJson("/api/v1/facility/service-requests/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/facility/service-requests/{$id}/start")->assertUnprocessable();
    $this->postJson("/api/v1/facility/service-requests/{$id}/cancel", ['reason' => 'No'])->assertUnprocessable();
    $this->putJson("/api/v1/facility/service-requests/{$id}", facRequestPayload())->assertUnprocessable();
});

test('the requester cancels a new request only and an open request past its SLA is overdue', function (): void {
    facAct($this->requester, $this->tenant);
    $first = $this->postJson('/api/v1/facility/service-requests', facRequestPayload())->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['title' => 'Altro']))->assertCreated()->json('data');

    $this->postJson("/api/v1/facility/service-requests/{$first['id']}/cancel", ['reason' => 'Risolto da solo'])->assertOk()->assertJsonPath('data.status', 'cancelled');

    facAct($this->technician, $this->tenant);
    $this->postJson("/api/v1/facility/service-requests/{$second['id']}/assign", ['assignee_id' => $this->technician->id])->assertOk();

    facAct($this->requester, $this->tenant);
    $this->postJson("/api/v1/facility/service-requests/{$second['id']}/cancel", ['reason' => 'Troppo tardi'])->assertForbidden();

    $this->travelTo('2027-05-13 09:00:00');
    $this->getJson("/api/v1/facility/service-requests/{$second['id']}")->assertOk()->assertJsonPath('data.is_overdue', true);
    $this->getJson('/api/v1/facility/service-requests?overdue=1')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/facility/service-requests/board')->assertOk()->assertJsonPath('data.overdue', 1);
});

test('a request links an existing maintenance work order or raises one for its asset', function (): void {
    facAct($this->technician, $this->tenant);
    $request = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['category' => 'repair', 'priority' => 'urgent']))->assertCreated()->json('data');
    $workOrder = MaintenanceWorkOrder::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->postJson("/api/v1/facility/service-requests/{$request['id']}/link-work-order", ['work_order_id' => (string) Str::uuid7()])
        ->assertUnprocessable()->assertJsonValidationErrors(['work_order_id']);
    $this->postJson("/api/v1/facility/service-requests/{$request['id']}/link-work-order", ['work_order_id' => $workOrder->id])
        ->assertOk()->assertJsonPath('data.work_order_id', $workOrder->id)->assertJsonPath('data.asset_id', $workOrder->asset_id);

    $second = $this->postJson('/api/v1/facility/service-requests', facRequestPayload(['category' => 'hvac', 'priority' => 'urgent']))->assertCreated()->json('data');
    $this->postJson("/api/v1/facility/service-requests/{$second['id']}/raise-work-order", [])->assertUnprocessable()->assertJsonValidationErrors(['asset_id']);

    $asset = FixedAsset::factory()->active()->create(['tenant_id' => $this->tenant->id]);
    $raised = $this->postJson("/api/v1/facility/service-requests/{$second['id']}/raise-work-order", ['asset_id' => $asset->id])
        ->assertOk()->assertJsonPath('data.asset_id', $asset->id)->json('data');

    $created = DB::table('eam_maintenance_work_orders')->where('id', $raised['work_order_id'])->first();
    expect($created)->not->toBeNull()
        ->and($created->asset_id)->toBe($asset->id)
        ->and($created->maintenance_type)->toBe('emergency')
        ->and($created->priority)->toBe('critical');

    $this->postJson("/api/v1/facility/service-requests/{$second['id']}/raise-work-order", ['asset_id' => $asset->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['work_order_id']);

    expect(FacilityServiceRequest::query()->whereNotNull('work_order_id')->count())->toBe(2);
});

test('internal comments are visible to operators only', function (): void {
    facAct($this->requester, $this->tenant);
    $request = $this->postJson('/api/v1/facility/service-requests', facRequestPayload())->assertCreated()->json('data');
    $this->postJson("/api/v1/facility/service-requests/{$request['id']}/comments", ['body' => 'Urgente per domani', 'is_internal' => true])
        ->assertCreated()->assertJsonPath('data.is_internal', false);

    facAct($this->technician, $this->tenant);
    $this->postJson("/api/v1/facility/service-requests/{$request['id']}/comments", ['body' => 'Chiedere al fornitore', 'is_internal' => true])
        ->assertCreated()->assertJsonPath('data.is_internal', true);
    $this->getJson("/api/v1/facility/service-requests/{$request['id']}/comments")->assertOk()->assertJsonCount(2, 'data');

    facAct($this->requester, $this->tenant);
    $this->getJson("/api/v1/facility/service-requests/{$request['id']}/comments")->assertOk()->assertJsonCount(1, 'data');

    facAct($this->colleague, $this->tenant);
    $this->getJson("/api/v1/facility/service-requests/{$request['id']}/comments")->assertForbidden();
});
