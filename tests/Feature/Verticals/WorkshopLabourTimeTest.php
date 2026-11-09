<?php

declare(strict_types=1);

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Workshop\Models\TimeEntry;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-09 08:00:00');
    $this->tenant = verticalTenant();
    $this->customer = verticalContact($this->tenant);
    $this->vehicle = Vehicle::factory()->create(['tenant_id' => $this->tenant->id, 'client_id' => $this->customer->id, 'license_plate' => 'TI 123456']);
    $this->user = verticalMember($this->tenant, 'admin');
    $this->technician = verticalMember($this->tenant, 'technician');
    verticalApi($this->user, $this->tenant);

    $this->folder = $this->postJson('/api/v1/workshop/folders', ['vehicle_id' => $this->vehicle->id, 'kind' => 'mechanics', 'currency' => 'CHF', 'mileage_in' => 42000, 'complaint' => 'Rumore freni'])->assertCreated()->json('data.id');
});

test('time is clocked on a folder in progress, one job at a time, and stopped in whole minutes', function (): void {
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/start")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'in progress'));
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/status", ['status' => 'in_progress'])->assertOk();

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/start", ['technician_user_id' => '0190f1a0-0000-7000-8000-000000000000'])->assertUnprocessable()->assertJsonValidationErrors('technician_user_id');
    $entry = $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/start", ['technician_user_id' => $this->technician->id, 'notes' => 'Freni'])->assertCreated()->assertJsonPath('data.minutes', null)->json('data.id');
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/start", ['technician_user_id' => $this->technician->id])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'already clocked'));

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/bill", ['hourly_rate' => '120', 'tax_rate' => '8.1'])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'still running'));

    $this->travel(95)->minutes();
    $this->postJson("/api/v1/workshop/time-entries/{$entry}/stop")->assertOk()->assertJsonPath('data.minutes', 95);
    $this->postJson("/api/v1/workshop/time-entries/{$entry}/stop")->assertUnprocessable();

    $summary = $this->getJson("/api/v1/workshop/folders/{$this->folder}/time-entries")->assertOk()->assertJsonCount(1, 'data.entries');
    expect($summary->json('data.technicians.0'))->toMatchArray(['technician_user_id' => $this->technician->id, 'minutes' => 95, 'hours' => '1.58', 'unbilled_minutes' => 95]);
});

test('unbilled time becomes one labour line per technician, once, at the rate given', function (): void {
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/bill", ['hourly_rate' => '120', 'tax_rate' => '8.1'])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'no unbilled time'));

    $entry = ['started_at' => '2026-11-09T08:00:00+01:00'];
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries", [...$entry, 'technician_user_id' => $this->technician->id, 'minutes' => 90])->assertCreated()->assertJsonPath('data.minutes', 90);
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries", [...$entry, 'technician_user_id' => $this->technician->id, 'minutes' => 30])->assertCreated();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries", [...$entry, 'technician_user_id' => $this->user->id, 'minutes' => 45])->assertCreated();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries", [...$entry, 'technician_user_id' => $this->user->id, 'minutes' => 0])->assertUnprocessable()->assertJsonValidationErrors('minutes');

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/bill", ['tax_rate' => '8.1'])->assertUnprocessable()->assertJsonValidationErrors('hourly_rate');
    $lines = $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/bill", ['hourly_rate' => '120', 'tax_rate' => '8.1', 'description' => 'Mano d\'opera'])->assertCreated()->assertJsonCount(2, 'data');
    $byTechnician = collect($lines->json('data'))->keyBy('technician_user_id');
    expect($byTechnician[$this->technician->id])->toMatchArray(['line_type' => 'labour', 'description' => 'Mano d\'opera', 'quantity' => '2.0000', 'line_total' => '240.00'])
        ->and($byTechnician[$this->user->id])->toMatchArray(['quantity' => '0.7500', 'line_total' => '90.00']);

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/bill", ['hourly_rate' => '120', 'tax_rate' => '8.1'])->assertUnprocessable();
    $this->getJson("/api/v1/workshop/folders/{$this->folder}")->assertOk()->assertJsonCount(2, 'data.lines');
    expect(TimeEntry::query()->whereNull('folder_line_id')->count())->toBe(0);

    $this->postJson("/api/v1/workshop/folders/{$this->folder}/status", ['status' => 'cancelled'])->assertOk();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries", [...$entry, 'technician_user_id' => $this->user->id, 'minutes' => 10])->assertUnprocessable();
});

test('the vehicle history lists its folders with the net total', function (): void {
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/lines", ['line_type' => 'part', 'description' => 'Pastiglie', 'quantity' => '1', 'unit_price' => '80.50', 'discount_percentage' => '10', 'tax_rate' => '8.1'])->assertCreated();

    $history = $this->getJson("/api/v1/workshop/vehicles/{$this->vehicle->id}/history")->assertOk()->assertJsonCount(1, 'data');
    expect($history->json('data.0'))->toMatchArray(['folder_number' => $history->json('data.0.folder_number'), 'status' => 'open', 'mileage_in' => 42000, 'net_total' => '72.45', 'complaint' => 'Rumore freni']);
});

test('read-only roles and other companies stay out of the labour times', function (): void {
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/status", ['status' => 'in_progress'])->assertOk();
    $entry = $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/start")->assertCreated()->assertJsonPath('data.technician_user_id', $this->user->id)->json('data.id');

    verticalApi(verticalMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson("/api/v1/workshop/folders/{$this->folder}/time-entries")->assertOk();
    $this->getJson("/api/v1/workshop/vehicles/{$this->vehicle->id}/history")->assertOk();
    $this->postJson("/api/v1/workshop/time-entries/{$entry}/stop")->assertForbidden();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/start")->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/workshop/folders/{$this->folder}/time-entries")->assertNotFound();
    $this->postJson("/api/v1/workshop/time-entries/{$entry}/stop")->assertNotFound();
    $this->postJson("/api/v1/workshop/folders/{$this->folder}/time-entries/bill", ['hourly_rate' => '120', 'tax_rate' => '8.1'])->assertNotFound();
    $this->getJson("/api/v1/workshop/vehicles/{$this->vehicle->id}/history")->assertNotFound();
});
