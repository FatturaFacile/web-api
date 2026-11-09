<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hospitality\Models\Reservation;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 09:00:00');
    $this->tenant = verticalTenant();
    $this->manager = verticalMember($this->tenant, 'admin');
    $this->waiter = verticalMember($this->tenant, 'member');

    verticalApi($this->manager, $this->tenant);
    $this->room = $this->postJson('/api/v1/hospitality/rooms', ['name' => 'Sala'])->assertCreated()->json('data.id');
    $this->table4 = $this->postJson("/api/v1/hospitality/rooms/{$this->room}/tables", ['name' => 'T4', 'seats' => 4])->assertCreated()->json('data.id');
    $this->table2 = $this->postJson("/api/v1/hospitality/rooms/{$this->room}/tables", ['name' => 'T2', 'seats' => 2])->assertCreated()->json('data.id');

    $this->book = fn (array $overrides = []) => $this->postJson('/api/v1/hospitality/reservations', [
        'guest_name' => 'Famiglia Bianchi', 'party_size' => 4, 'starts_at' => '2026-11-20T20:00:00+01:00', 'duration_minutes' => 90, 'table_id' => $this->table4, ...$overrides,
    ]);
});

test('a table is booked once per interval and the party must fit', function (): void {
    $id = ($this->book)()->assertCreated()->assertJsonPath('data.status', 'booked')->assertJsonPath('data.starts_at', '2026-11-20T19:00:00.000000Z')->json('data.id');

    ($this->book)(['starts_at' => '2026-11-20T21:00:00+01:00'])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'already reserved'));
    ($this->book)(['starts_at' => '2026-11-20T21:30:00+01:00'])->assertCreated();
    ($this->book)(['table_id' => $this->table2, 'party_size' => 3, 'starts_at' => '2026-11-20T12:00:00+01:00'])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'does not fit'));
    ($this->book)(['duration_minutes' => 5])->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
    ($this->book)(['guest_name' => null])->assertUnprocessable()->assertJsonValidationErrors('guest_name');

    $this->putJson("/api/v1/hospitality/reservations/{$id}", ['starts_at' => '2026-11-20T21:30:00+01:00'])->assertUnprocessable();
    $this->putJson("/api/v1/hospitality/reservations/{$id}", ['starts_at' => '2026-11-20T18:00:00+01:00', 'party_size' => 2])->assertOk()->assertJsonPath('data.party_size', 2);

    $this->postJson("/api/v1/hospitality/reservations/{$id}/status", ['status' => 'cancelled'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->postJson("/api/v1/hospitality/reservations/{$id}/status", ['status' => 'cancelled', 'reason' => 'Disdetta telefonica'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/hospitality/reservations/{$id}/status", ['status' => 'no_show', 'reason' => 'Mai arrivati'])->assertUnprocessable();
    $this->putJson("/api/v1/hospitality/reservations/{$id}", ['party_size' => 3])->assertUnprocessable();
    ($this->book)(['starts_at' => '2026-11-20T18:30:00+01:00'])->assertCreated();
});

test('the calendar shows the day of the venue and availability excludes held tables', function (): void {
    ($this->book)()->assertCreated();
    ($this->book)(['table_id' => null, 'guest_name' => 'Rossi', 'party_size' => 2, 'starts_at' => '2026-11-21T00:30:00+01:00'])->assertCreated();

    $day = $this->getJson('/api/v1/hospitality/reservations?date=2026-11-20')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guest_name', 'Famiglia Bianchi');
    $this->getJson('/api/v1/hospitality/reservations?date=2026-11-21')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.guest_name', 'Rossi');
    $this->getJson("/api/v1/hospitality/reservations?date=2026-11-20&room_id={$this->room}&status=booked")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/hospitality/reservations?date=20-11-2026')->assertUnprocessable()->assertJsonValidationErrors('date');
    expect($day->json('data.0.id'))->toBeString();

    $free = $this->getJson('/api/v1/hospitality/reservations/availability?starts_at='.urlencode('2026-11-20T20:30:00+01:00').'&duration_minutes=60&party_size=2')->assertOk();
    expect(collect($free->json('data'))->pluck('name')->all())->toBe(['T2']);
    $this->getJson('/api/v1/hospitality/reservations/availability?starts_at='.urlencode('2026-11-20T20:30:00+01:00').'&duration_minutes=60&party_size=5')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/hospitality/reservations/availability?duration_minutes=60&party_size=2')->assertUnprocessable()->assertJsonValidationErrors('starts_at');
});

test('seating the guests opens the order and completing frees the booking', function (): void {
    $id = ($this->book)(['table_id' => null])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/hospitality/reservations/{$id}/seat")->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Assign a table'));
    $this->postJson("/api/v1/hospitality/reservations/{$id}/seat", ['table_id' => $this->table2])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'does not fit'));

    verticalApi($this->waiter, $this->tenant);
    $seated = $this->postJson("/api/v1/hospitality/reservations/{$id}/seat", ['table_id' => $this->table4])->assertOk()->assertJsonPath('data.status', 'seated')->json('data');
    expect($seated['order_id'])->toBeString();
    $this->getJson("/api/v1/hospitality/orders/{$seated['order_id']}")->assertOk()->assertJsonPath('data.covers', 4);
    $this->postJson("/api/v1/hospitality/reservations/{$id}/seat")->assertUnprocessable();
    $this->postJson("/api/v1/hospitality/reservations/{$id}/status", ['status' => 'no_show', 'reason' => 'Gia seduti'])->assertUnprocessable();
    $this->postJson("/api/v1/hospitality/reservations/{$id}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');
    $this->getJson("/api/v1/hospitality/reservations/{$id}")->assertOk()->assertJsonPath('data.table_id', $this->table4);
});

test('other companies and read-only roles stay out of the reservations', function (): void {
    $id = ($this->book)()->assertCreated()->json('data.id');

    $reader = verticalMember($this->tenant, 'accountant');
    verticalApi($reader, $this->tenant);
    $this->getJson('/api/v1/hospitality/reservations?date=2026-11-20')->assertOk();
    ($this->book)()->assertForbidden();
    $this->postJson("/api/v1/hospitality/reservations/{$id}/status", ['status' => 'cancelled', 'reason' => 'No'])->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/hospitality/reservations/{$id}")->assertNotFound();
    $this->putJson("/api/v1/hospitality/reservations/{$id}", ['party_size' => 2])->assertNotFound();
    $this->postJson("/api/v1/hospitality/reservations/{$id}/seat")->assertNotFound();
    ($this->book)()->assertUnprocessable()->assertJsonValidationErrors('table_id');
    $this->getJson('/api/v1/hospitality/reservations?date=2026-11-20')->assertOk()->assertJsonCount(0, 'data');
    expect(Reservation::query()->withoutGlobalScopes()->count())->toBe(1);
});
