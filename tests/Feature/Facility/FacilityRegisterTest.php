<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/FacilityTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 08:00:00');
    $this->tenant = facTenant();
    $this->manager = facMember($this->tenant, 'facility_manager');
    $this->member = facMember($this->tenant, 'member');
    facAct($this->manager, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('a facility manager builds the tree of floors and spaces and the hierarchy rules hold', function (): void {
    $building = $this->postJson('/api/v1/facility/buildings', ['code' => 'HQ', 'name' => 'Sede centrale', 'city' => 'Milano', 'country' => 'IT'])
        ->assertCreated()->assertJsonPath('data.code', 'HQ')->json('data');

    $this->postJson('/api/v1/facility/buildings', ['code' => 'HQ', 'name' => 'Duplicato'])->assertUnprocessable()->assertJsonValidationErrors(['code']);

    $floor = $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'kind' => 'floor', 'code' => 'P1', 'name' => 'Primo piano', 'floor_level' => 1])
        ->assertCreated()->json('data');

    $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'parent_id' => $floor['id'], 'kind' => 'floor', 'code' => 'P2', 'name' => 'Sotto'])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    $office = $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'parent_id' => $floor['id'], 'kind' => 'office', 'code' => 'U1', 'name' => 'Open space', 'capacity' => 12, 'area_sqm' => 85.5])
        ->assertCreated()->assertJsonPath('data.area_sqm', '85.50')->json('data');

    $desk = $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'parent_id' => $office['id'], 'kind' => 'desk', 'code' => 'D1', 'name' => 'Scrivania 1', 'capacity' => 1, 'is_bookable' => true, 'amenities' => ['monitor', 'dock']])
        ->assertCreated()->assertJsonPath('data.is_bookable', true)->json('data');

    $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'parent_id' => $desk['id'], 'kind' => 'desk', 'code' => 'D2', 'name' => 'Sotto la scrivania'])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'kind' => 'meeting_room', 'code' => 'R1', 'name' => 'Sala', 'is_bookable' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['capacity']);

    $this->postJson('/api/v1/facility/spaces', ['building_id' => $building['id'], 'kind' => 'floor', 'code' => 'P9', 'name' => 'Piano', 'is_bookable' => true, 'capacity' => 5])
        ->assertUnprocessable()->assertJsonValidationErrors(['is_bookable']);

    $tree = $this->getJson("/api/v1/facility/buildings/{$building['id']}/tree")->assertOk()->json('data');
    expect($tree)->toHaveCount(1)
        ->and($tree[0]['code'])->toBe('P1')
        ->and($tree[0]['children'][0]['code'])->toBe('U1')
        ->and($tree[0]['children'][0]['children'][0]['code'])->toBe('D1');

    $this->getJson("/api/v1/facility/spaces?building_id={$building['id']}&bookable=1")->assertOk()->assertJsonCount(1, 'data');
});

test('a space cannot become its own ancestor and containers keep their children', function (): void {
    $building = facBuilding($this->tenant);
    $floor = facSpace($this->tenant, 'office', ['building_id' => $building->id, 'kind' => 'floor', 'code' => 'F', 'is_bookable' => false, 'capacity' => null]);
    $office = facSpace($this->tenant, 'office', ['building_id' => $building->id, 'parent_id' => $floor->id, 'code' => 'O']);
    $warehouse = facSpace($this->tenant, 'warehouse', ['building_id' => $building->id, 'parent_id' => $office->id, 'code' => 'W']);

    $payload = ['building_id' => $building->id, 'kind' => 'office', 'code' => 'O', 'name' => 'Ufficio', 'parent_id' => $warehouse->id, 'capacity' => 4];
    $this->putJson("/api/v1/facility/spaces/{$office->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    $this->putJson("/api/v1/facility/spaces/{$office->id}", [...$payload, 'parent_id' => $floor->id, 'kind' => 'desk'])
        ->assertUnprocessable()->assertJsonValidationErrors(['kind']);

    $this->deleteJson("/api/v1/facility/spaces/{$office->id}")->assertUnprocessable();
    $this->deleteJson("/api/v1/facility/buildings/{$building->id}")->assertUnprocessable();

    $this->deleteJson("/api/v1/facility/spaces/{$warehouse->id}")->assertNoContent();
    $this->deleteJson("/api/v1/facility/spaces/{$office->id}")->assertNoContent();
    $this->deleteJson("/api/v1/facility/spaces/{$floor->id}")->assertNoContent();
    $this->deleteJson("/api/v1/facility/buildings/{$building->id}")->assertNoContent();
});

test('members read the register but only facility managers change it', function (): void {
    $building = facBuilding($this->tenant);
    facAct($this->member, $this->tenant);

    $this->getJson('/api/v1/facility/buildings')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/facility/buildings/{$building->id}")->assertOk();
    $this->postJson('/api/v1/facility/buildings', ['code' => 'X', 'name' => 'Nuovo'])->assertForbidden();
    $this->putJson("/api/v1/facility/buildings/{$building->id}", ['code' => 'X', 'name' => 'Cambiato'])->assertForbidden();
    $this->deleteJson("/api/v1/facility/buildings/{$building->id}")->assertForbidden();
    $this->getJson('/api/v1/facility/overview')->assertOk()->assertJsonPath('data.buildings', 1);
});

test('the register of another company is invisible', function (): void {
    app(TenantContext::class)->clear();
    $other = facTenant('Altra Srl');
    $foreign = facBuilding($other);
    $foreignSpace = facSpace($other, 'meeting_room', ['building_id' => $foreign->id]);
    facAct($this->manager, $this->tenant);

    $this->getJson("/api/v1/facility/buildings/{$foreign->id}")->assertNotFound();
    $this->getJson("/api/v1/facility/spaces/{$foreignSpace->id}")->assertNotFound();
    $this->getJson('/api/v1/facility/buildings')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson('/api/v1/facility/spaces', ['building_id' => $foreign->id, 'kind' => 'floor', 'code' => 'P1', 'name' => 'Piano'])
        ->assertUnprocessable()->assertJsonValidationErrors(['building_id']);
});

test('the facility manager permission opens the register to a member', function (): void {
    $delegate = facMember($this->tenant, 'member', ['facility.manage']);
    facAct($delegate, $this->tenant);

    $this->postJson('/api/v1/facility/buildings', ['code' => 'LU', 'name' => 'Filiale Lugano', 'country' => 'CH'])->assertCreated();
});
