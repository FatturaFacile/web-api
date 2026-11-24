<?php

declare(strict_types=1);

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-09 08:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 654321']);
    $this->admin = verticalMember($this->tenant, 'admin');
    $this->mario = verticalMember($this->tenant, 'technician');
    $this->luca = verticalMember($this->tenant, 'technician');
    verticalApi($this->admin, $this->tenant);

    $open = fn () => $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $this->vehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF'])->assertCreated()->json('data.id');
    $this->folderA = $open();
    $this->folderB = $open();
    $this->bay = $this->postJson('/api/v1/workshop/bays', ['code' => 'LIFT-1', 'name' => 'Lift 1'])->assertCreated()->json('data.id');

    $this->plan = fn (array $overrides = []) => $this->postJson('/api/v1/workshop/schedule', [
        'folder_id' => $this->folderA, 'technician_user_id' => $this->mario->id, 'bay_id' => $this->bay,
        'starts_at' => '2026-11-10T08:00:00+01:00', 'ends_at' => '2026-11-10T10:00:00+01:00', ...$overrides,
    ]);
});

test('a technician and a bay each hold one slot at a time, back-to-back slots are fine', function (): void {
    ($this->plan)()->assertCreated()->assertJsonPath('data.status', 'planned');

    ($this->plan)(['folder_id' => $this->folderB, 'bay_id' => null, 'starts_at' => '2026-11-10T09:00:00+01:00', 'ends_at' => '2026-11-10T11:00:00+01:00'])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'technician already'));
    ($this->plan)(['folder_id' => $this->folderB, 'technician_user_id' => $this->luca->id, 'starts_at' => '2026-11-10T09:00:00+01:00', 'ends_at' => '2026-11-10T11:00:00+01:00'])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'bay is already'));

    ($this->plan)(['folder_id' => $this->folderB, 'starts_at' => '2026-11-10T10:00:00+01:00', 'ends_at' => '2026-11-10T12:00:00+01:00'])->assertCreated();
    ($this->plan)(['folder_id' => $this->folderB, 'technician_user_id' => $this->luca->id, 'bay_id' => null, 'starts_at' => '2026-11-10T09:00:00+01:00', 'ends_at' => '2026-11-10T11:00:00+01:00'])->assertCreated();
});

test('validation: window order, folder, technician membership and inactive bay', function (): void {
    ($this->plan)(['ends_at' => '2026-11-10T07:00:00+01:00'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    ($this->plan)(['technician_user_id' => '0190f1a0-0000-7000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('technician_user_id');
    ($this->plan)(['folder_id' => '0190f1a0-0000-7000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('folder_id');

    $this->putJson("/api/v1/workshop/bays/{$this->bay}", ['is_active' => false])->assertOk();
    ($this->plan)()->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'not active'));
    $this->postJson('/api/v1/workshop/bays', ['code' => 'LIFT-1', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('code');

    $this->postJson("/api/v1/workshop/folders/{$this->folderB}/status", ['status' => 'cancelled'])->assertOk();
    ($this->plan)(['folder_id' => $this->folderB, 'bay_id' => null])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'open or in-progress'));
});

test('a planned slot can be moved with the same conflict check; started slots cannot', function (): void {
    $first = ($this->plan)()->assertCreated()->json('data.id');
    $second = ($this->plan)(['folder_id' => $this->folderB, 'starts_at' => '2026-11-10T10:00:00+01:00', 'ends_at' => '2026-11-10T12:00:00+01:00'])->assertCreated()->json('data.id');

    $this->putJson("/api/v1/workshop/schedule/{$second}", ['starts_at' => '2026-11-10T09:30:00+01:00'])->assertUnprocessable();
    $this->putJson("/api/v1/workshop/schedule/{$second}", ['ends_at' => '2026-11-10T09:00:00+01:00'])->assertUnprocessable();
    $this->putJson("/api/v1/workshop/schedule/{$second}", ['starts_at' => '2026-11-10T14:00:00+01:00', 'ends_at' => '2026-11-10T16:00:00+01:00'])->assertOk();
    $this->putJson("/api/v1/workshop/schedule/{$second}", ['technician_user_id' => $this->luca->id])->assertOk()->assertJsonPath('data.technician_user_id', $this->luca->id);

    $this->postJson("/api/v1/workshop/schedule/{$first}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->putJson("/api/v1/workshop/schedule/{$first}", ['notes' => 'late'])->assertUnprocessable();
    $this->postJson("/api/v1/workshop/schedule/{$first}/start")->assertUnprocessable();
    $this->postJson("/api/v1/workshop/schedule/{$first}/complete")->assertOk()->assertJsonPath('data.status', 'done');
    $this->postJson("/api/v1/workshop/schedule/{$first}/cancel")->assertUnprocessable();
});

test('a cancelled or finished slot frees the technician and the bay, and the schedule lists a window', function (): void {
    $first = ($this->plan)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/workshop/schedule/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    ($this->plan)(['folder_id' => $this->folderB])->assertCreated();

    $window = '?from=2026-11-10T00:00:00%2B01:00&to=2026-11-11T00:00:00%2B01:00';
    $this->getJson('/api/v1/workshop/schedule'.$window)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.folder.id', $this->folderA);
    $this->getJson('/api/v1/workshop/schedule'.$window.'&technician_user_id='.$this->luca->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/workshop/schedule?from=2026-11-12T00:00:00%2B01:00&to=2026-11-13T00:00:00%2B01:00')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/workshop/schedule?from=2026-01-01T00:00:00%2B01:00&to=2026-12-31T00:00:00%2B01:00')->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson('/api/v1/workshop/schedule')->assertUnprocessable()->assertJsonValidationErrors('from');
});

test('the schedule belongs to the company', function (): void {
    $slot = ($this->plan)()->assertCreated()->json('data.id');

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->postJson("/api/v1/workshop/schedule/{$slot}/start")->assertNotFound();
    $this->getJson('/api/v1/workshop/bays')->assertOk()->assertJsonCount(0, 'data');
    ($this->plan)()->assertUnprocessable()->assertJsonValidationErrors('folder_id');
});
