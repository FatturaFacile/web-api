<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hospitality\Models\Accommodation;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 09:00:00');
    $this->tenant = verticalTenant();
    $this->manager = verticalMember($this->tenant, 'admin');
    $this->desk = verticalMember($this->tenant, 'member');

    verticalApi($this->manager, $this->tenant);
    $this->double = $this->postJson('/api/v1/hospitality/room-types', ['code' => 'DBL', 'name' => 'Double', 'max_occupancy' => 2, 'base_rate' => 100])->assertCreated()->json('data.id');
    $this->room101 = $this->postJson('/api/v1/hospitality/accommodations', ['room_type_id' => $this->double, 'number' => '101'])->assertCreated()->json('data.id');
    $this->room102 = $this->postJson('/api/v1/hospitality/accommodations', ['room_type_id' => $this->double, 'number' => '102'])->assertCreated()->json('data.id');

    $this->book = fn (array $overrides = []) => $this->postJson('/api/v1/hospitality/stays', [
        'guest_name' => 'Maria Rossi', 'room_type_id' => $this->double, 'check_in_date' => '2026-11-19', 'check_out_date' => '2026-11-22', 'adults' => 2, ...$overrides,
    ]);
});

test('the catalog keeps codes and numbers unique and is reserved to managers', function (): void {
    $this->postJson('/api/v1/hospitality/room-types', ['code' => 'DBL', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/hospitality/accommodations', ['room_type_id' => $this->double, 'number' => '101'])->assertUnprocessable()->assertJsonValidationErrors('number');
    $this->postJson('/api/v1/hospitality/accommodations', ['room_type_id' => (string) fake()->uuid(), 'number' => '999'])->assertUnprocessable()->assertJsonValidationErrors('room_type_id');
    $this->putJson("/api/v1/hospitality/accommodations/{$this->room102}", ['status' => 'out_of_service'])->assertOk()->assertJsonPath('data.status', 'out_of_service');
    $this->putJson("/api/v1/hospitality/accommodations/{$this->room102}", ['status' => 'available'])->assertOk();

    verticalApi($this->desk, $this->tenant);
    $this->getJson('/api/v1/hospitality/room-types')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/hospitality/room-types', ['code' => 'SUI', 'name' => 'Suite'])->assertForbidden();
    $this->postJson('/api/v1/hospitality/rate-plans', ['code' => 'X', 'name' => 'X', 'nightly_rate' => 90, 'valid_from' => '2026-11-01'])->assertForbidden();
});

test('availability is the minimum of the free rooms over the nights and respects bookings and out-of-service rooms', function (): void {
    $query = '?room_type_id='.$this->double.'&from=2026-11-19&to=2026-11-22';
    $this->getJson('/api/v1/hospitality/stay-availability'.$query)->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.available', 2);

    ($this->book)(['check_in_date' => '2026-11-20', 'check_out_date' => '2026-11-21'])->assertCreated();
    $this->getJson('/api/v1/hospitality/stay-availability'.$query)->assertJsonPath('data.available', 1)->assertJsonPath('data.nights.1.booked', 1)->assertJsonPath('data.nights.0.available', 2);

    ($this->book)()->assertCreated();
    ($this->book)()->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'No room'));
    ($this->book)(['check_in_date' => '2026-11-21', 'check_out_date' => '2026-11-23'])->assertCreated();

    $this->putJson("/api/v1/hospitality/accommodations/{$this->room102}", ['status' => 'out_of_service'])->assertOk();
    $this->getJson('/api/v1/hospitality/stay-availability?room_type_id='.$this->double.'&from=2026-12-01&to=2026-12-02')->assertJsonPath('data.available', 1);
    $this->getJson('/api/v1/hospitality/stay-availability?room_type_id='.$this->double.'&from=2026-12-02&to=2026-12-01')->assertUnprocessable()->assertJsonValidationErrors('to');
});

test('a stay is priced by the chosen plan, the best seasonal plan or the base rate and the party must fit', function (): void {
    $this->postJson('/api/v1/hospitality/rate-plans', ['code' => 'LOW', 'name' => 'Low season', 'room_type_id' => $this->double, 'nightly_rate' => 80, 'valid_from' => '2026-11-01', 'valid_to' => '2026-11-20'])->assertCreated();
    $high = $this->postJson('/api/v1/hospitality/rate-plans', ['code' => 'XMAS', 'name' => 'Christmas', 'nightly_rate' => 150, 'valid_from' => '2026-11-21', 'priority' => 5, 'min_stay' => 2])->assertCreated()->json('data.id');

    $quote = $this->getJson('/api/v1/hospitality/stay-quote?room_type_id='.$this->double.'&from=2026-11-19&to=2026-11-23')->assertOk()->json('data');
    expect($quote['rates'])->toBe(['80.00', '80.00', '150.00', '150.00'])->and($quote['total'])->toBe('460.00');
    $this->getJson('/api/v1/hospitality/stay-quote?room_type_id='.$this->double.'&from=2026-11-21&to=2026-11-22')->assertJsonPath('data.rates.0', '100.00');
    $this->getJson('/api/v1/hospitality/stay-quote?room_type_id='.$this->double.'&from=2026-11-19&to=2026-11-23&rate_plan_id='.$high)->assertUnprocessable();

    $stay = ($this->book)(['check_in_date' => '2026-11-21', 'check_out_date' => '2026-11-23', 'rate_plan_id' => $high])->assertCreated()->assertJsonPath('data.room_total', '300.00')->json('data');
    expect($stay['number'])->toBe('STY-2026-0001')->and($stay['nightly_rates'])->toBe(['150.00', '150.00']);

    ($this->book)(['adults' => 3])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'sleeps at most'));
    ($this->book)(['check_out_date' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors('check_out_date');
});

test('check-in assigns a clean free room, posts the folio and check-out needs a settled folio and dirties the room', function (): void {
    $stayId = ($this->book)()->assertCreated()->json('data.id');

    $checkedIn = $this->postJson("/api/v1/hospitality/stays/{$stayId}/check-in", ['city_tax_per_person_night' => 2])->assertOk()->assertJsonPath('data.status', 'checked_in')->json('data');
    expect($checkedIn['accommodation_id'])->toBe($this->room101)
        ->and($checkedIn['folio_balance'])->toBe('312.00')
        ->and(collect($checkedIn['folio_entries'])->pluck('category')->sort()->values()->all())->toBe(['city_tax', 'room', 'room', 'room']);
    $this->postJson("/api/v1/hospitality/stays/{$stayId}/check-in")->assertUnprocessable();

    $this->postJson("/api/v1/hospitality/stays/{$stayId}/charges", ['category' => 'restaurant', 'description' => 'Dinner', 'quantity' => 2, 'unit_amount' => 25.5])->assertCreated()->assertJsonPath('data.amount', '51.00');
    $this->postJson("/api/v1/hospitality/stays/{$stayId}/payments", ['amount' => 200, 'payment_method' => 'card'])->assertCreated();
    $this->postJson("/api/v1/hospitality/stays/{$stayId}/check-out")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, '163.00'));

    $dinner = collect($this->getJson("/api/v1/hospitality/stays/{$stayId}")->json('data.folio_entries'))->firstWhere('category', 'restaurant');
    $this->postJson("/api/v1/hospitality/folio-entries/{$dinner['id']}/void")->assertOk();
    $this->getJson("/api/v1/hospitality/stays/{$stayId}")->assertJsonPath('data.folio_balance', '112.00');
    $this->postJson("/api/v1/hospitality/folio-entries/{$dinner['id']}/void")->assertUnprocessable();

    $this->postJson("/api/v1/hospitality/stays/{$stayId}/payments", ['amount' => 112, 'payment_method' => 'cash'])->assertCreated();
    $this->postJson("/api/v1/hospitality/stays/{$stayId}/check-out")->assertOk()->assertJsonPath('data.status', 'checked_out')->assertJsonPath('data.folio_balance', '0.00');

    expect(Accommodation::query()->findOrFail($this->room101)->housekeeping_status)->toBe('dirty');
    $this->postJson("/api/v1/hospitality/stays/{$stayId}/charges", ['category' => 'extra', 'description' => 'Late', 'unit_amount' => 5])->assertUnprocessable();
});

test('check-in is refused before the arrival date and onto a dirty or busy room; housekeeping makes the room clean again', function (): void {
    $future = ($this->book)(['check_in_date' => '2026-11-25', 'check_out_date' => '2026-11-26'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/stays/{$future}/check-in")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'not possible earlier'));

    $first = ($this->book)(['check_out_date' => '2026-11-20'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/stays/{$first}/check-in", ['accommodation_id' => $this->room101])->assertOk();
    $second = ($this->book)(['check_out_date' => '2026-11-20'])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/stays/{$second}/check-in", ['accommodation_id' => $this->room101])->assertUnprocessable();
    $this->putJson("/api/v1/hospitality/accommodations/{$this->room102}", ['housekeeping_status' => 'dirty'])->assertOk();
    $this->postJson("/api/v1/hospitality/stays/{$second}/check-in")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'No clean room'));

    $this->postJson("/api/v1/hospitality/stays/{$first}/payments", ['amount' => 100, 'payment_method' => 'cash'])->assertCreated();
    $this->postJson("/api/v1/hospitality/stays/{$first}/check-out")->assertOk();
    $task = $this->getJson('/api/v1/hospitality/housekeeping-tasks?status=pending')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.task_type', 'checkout_clean')->json('data.0');
    $own = $this->postJson('/api/v1/hospitality/housekeeping-tasks', ['accommodation_id' => $this->room102, 'task_type' => 'checkout_clean'])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/hospitality/housekeeping-tasks/{$task['id']}/start", ['assigned_to' => $this->desk->id])->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/hospitality/housekeeping-tasks/{$task['id']}/start")->assertUnprocessable();
    $this->postJson("/api/v1/hospitality/housekeeping-tasks/{$task['id']}/complete")->assertOk()->assertJsonPath('data.status', 'done');
    $this->postJson("/api/v1/hospitality/housekeeping-tasks/{$task['id']}/complete")->assertUnprocessable();
    $this->postJson("/api/v1/hospitality/housekeeping-tasks/{$own}/complete")->assertOk();

    expect(Accommodation::query()->findOrFail($this->room101)->housekeeping_status)->toBe('clean')
        ->and(Accommodation::query()->findOrFail($this->room102)->housekeeping_status)->toBe('clean');
    $this->postJson("/api/v1/hospitality/stays/{$second}/check-in")->assertOk()->assertJsonPath('data.accommodation_id', $this->room101);
});

test('a booked stay is cancelled or marked no-show and frees the room type; other stays are not reachable', function (): void {
    $one = ($this->book)()->assertCreated()->json('data.id');
    $two = ($this->book)()->assertCreated()->json('data.id');
    ($this->book)()->assertUnprocessable();

    $this->postJson("/api/v1/hospitality/stays/{$one}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/hospitality/stays/{$one}/no-show")->assertUnprocessable();
    $this->postJson("/api/v1/hospitality/stays/{$two}/no-show")->assertOk()->assertJsonPath('data.status', 'no_show');
    ($this->book)()->assertCreated();
    $this->getJson('/api/v1/hospitality/stays?status=booked')->assertOk()->assertJsonCount(1, 'data');

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/hospitality/stays/{$one}")->assertNotFound();
    $this->getJson('/api/v1/hospitality/stays')->assertOk()->assertJsonCount(0, 'data');
});
