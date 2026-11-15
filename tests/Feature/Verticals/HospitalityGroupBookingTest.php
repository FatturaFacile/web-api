<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Hospitality\Jobs\ReleaseExpiredGroupBlocksJob;
use Modules\Hospitality\Models\Accommodation;
use Modules\Hospitality\Models\Stay;
use Modules\Hospitality\Services\GroupBookingService;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 09:00:00');
    $this->tenant = verticalTenant();
    $this->manager = verticalMember($this->tenant, 'admin');
    $this->desk = verticalMember($this->tenant, 'member');
    $this->customer = verticalContact($this->tenant);

    verticalApi($this->manager, $this->tenant);
    $this->double = $this->postJson('/api/v1/hospitality/room-types', ['code' => 'DBL', 'name' => 'Double', 'max_occupancy' => 2, 'base_rate' => 100])->assertCreated()->json('data.id');
    $this->suite = $this->postJson('/api/v1/hospitality/room-types', ['code' => 'SUI', 'name' => 'Suite', 'max_occupancy' => 4, 'base_rate' => 250])->assertCreated()->json('data.id');
    foreach (['101', '102', '103'] as $number) {
        $this->postJson('/api/v1/hospitality/accommodations', ['room_type_id' => $this->double, 'number' => $number])->assertCreated();
    }
    $this->postJson('/api/v1/hospitality/accommodations', ['room_type_id' => $this->suite, 'number' => '201'])->assertCreated();

    $this->block = fn (array $overrides = []) => $this->postJson('/api/v1/hospitality/group-blocks', [
        'name' => 'Wedding Bianchi',
        'organizer' => 'Anna Bianchi',
        'check_in_date' => '2026-11-25',
        'check_out_date' => '2026-11-28',
        'release_date' => '2026-11-22',
        'rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 2, 'nightly_rate' => '90.00']],
        ...$overrides,
    ]);
    $this->grid = fn (string $from = '2026-11-25', string $to = '2026-11-28') => $this->getJson("/api/v1/hospitality/availability-grid?from={$from}&to={$to}");
});

test('a block holds rooms against the general availability and the grid shows them as held', function (): void {
    $block = ($this->block)()->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.pickup.0.remaining', 2);
    expect($block->json('data.number'))->toStartWith('GRP-');

    $double = collect(($this->grid)()->assertOk()->json('data'))->firstWhere('code', 'DBL');
    expect($double['total'])->toBe(3)
        ->and(array_column($double['nights'], 'held'))->toBe([2, 2, 2])
        ->and(array_column($double['nights'], 'available'))->toBe([1, 1, 1]);

    $outside = collect(($this->grid)('2026-11-28', '2026-11-30')->json('data'))->firstWhere('code', 'DBL');
    expect(array_column($outside['nights'], 'available'))->toBe([3, 3]);

    $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Walk In', 'room_type_id' => $this->double, 'check_in_date' => '2026-11-26', 'check_out_date' => '2026-11-27', 'adults' => 2])->assertCreated();
    $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Walk In 2', 'room_type_id' => $this->double, 'check_in_date' => '2026-11-26', 'check_out_date' => '2026-11-27', 'adults' => 2])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'No room'));
});

test('a block cannot hold more rooms than are free and the payload is validated', function (): void {
    ($this->block)(['rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 4]]])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'Only 3'));
    ($this->block)()->assertCreated();
    ($this->block)(['name' => 'Second', 'rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 2]]])->assertUnprocessable();
    ($this->block)(['release_date' => '2026-11-26'])->assertUnprocessable()->assertJsonValidationErrors('release_date');
    ($this->block)(['check_out_date' => '2026-11-25'])->assertUnprocessable()->assertJsonValidationErrors('check_out_date');
    ($this->block)(['rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 1], ['room_type_id' => $this->double, 'rooms_blocked' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('rooms.0.room_type_id');
});

test('stays are picked up from the block at the negotiated rate until the rooms are exhausted', function (): void {
    $id = ($this->block)()->assertCreated()->json('data.id');
    $pick = fn (array $overrides = []) => $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2, ...$overrides]);

    $stay = $pick()->assertCreated()->assertJsonPath('data.check_in_date', '2026-11-25')->assertJsonPath('data.check_out_date', '2026-11-28')->assertJsonPath('data.room_total', '270.00');
    expect(Stay::query()->findOrFail($stay->json('data.id'))->group_block_id)->toBe($id);
    $pick()->assertCreated();
    $pick()->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'picked up'));
    $pick(['room_type_id' => $this->suite])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'no rooms'));

    $shown = $this->getJson("/api/v1/hospitality/group-blocks/{$id}")->assertOk()->assertJsonCount(2, 'data.stays')->json('data.pickup.0');
    expect($shown)->toMatchArray(['rooms_blocked' => 2, 'picked_up' => 2, 'remaining' => 0]);

    $double = collect(($this->grid)()->json('data'))->firstWhere('code', 'DBL');
    expect(array_column($double['nights'], 'held'))->toBe([0, 0, 0])
        ->and(array_column($double['nights'], 'available'))->toBe([1, 1, 1]);
});

test('a pickup must fall inside the block dates', function (): void {
    $id = ($this->block)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2, 'check_in_date' => '2026-11-24', 'check_out_date' => '2026-11-26'])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'inside the dates'));
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2, 'check_in_date' => '2026-11-26', 'check_out_date' => '2026-11-27'])->assertCreated();
});

test('the unpicked rooms go back on sale on the release date, or when the block is released by hand', function (): void {
    $id = ($this->block)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2])->assertCreated();

    $this->travelTo('2026-11-23 09:00:00');
    $double = collect(($this->grid)()->json('data'))->firstWhere('code', 'DBL');
    expect(array_column($double['nights'], 'held'))->toBe([0, 0, 0])->and(array_column($double['nights'], 'available'))->toBe([2, 2, 2]);
    $this->getJson("/api/v1/hospitality/group-blocks/{$id}")->assertJsonPath('data.is_expired', true);
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Late', 'room_type_id' => $this->double, 'adults' => 2])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'no longer open'));

    $this->travelTo('2026-11-19 09:00:00');
    $other = ($this->block)(['name' => 'Conference', 'rooms' => [['room_type_id' => $this->suite, 'rooms_blocked' => 1]]])->assertCreated()->json('data.id');
    $suite = fn () => collect(($this->grid)()->json('data'))->firstWhere('code', 'SUI');
    expect(array_column($suite()['nights'], 'available'))->toBe([0, 0, 0]);
    $this->postJson("/api/v1/hospitality/group-blocks/{$other}/release")->assertOk()->assertJsonPath('data.status', 'released');
    expect(array_column($suite()['nights'], 'available'))->toBe([1, 1, 1]);
    $this->postJson("/api/v1/hospitality/group-blocks/{$other}/release")->assertUnprocessable();
});

test('a block can change its rooms but never below the pickup, and cancelling frees everything', function (): void {
    $id = ($this->block)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2])->assertCreated();

    $this->putJson("/api/v1/hospitality/group-blocks/{$id}", ['rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 3]], 'name' => 'Wedding Bianchi 2'])->assertOk()->assertJsonPath('data.pickup.0.rooms_blocked', 3)->assertJsonPath('data.name', 'Wedding Bianchi 2');
    $this->putJson("/api/v1/hospitality/group-blocks/{$id}", ['rooms' => [['room_type_id' => $this->suite, 'rooms_blocked' => 1]]])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'picked up'));
    $this->putJson("/api/v1/hospitality/group-blocks/{$id}", ['rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 1]]])->assertOk();
    $this->putJson("/api/v1/hospitality/group-blocks/{$id}", ['rooms' => [['room_type_id' => $this->double, 'rooms_blocked' => 4]]])->assertUnprocessable();
    $this->putJson("/api/v1/hospitality/group-blocks/{$id}", ['check_in_date' => '2026-11-26'])->assertUnprocessable()->assertJsonValidationErrors('check_in_date');

    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/cancel")->assertUnprocessable();
    $this->putJson("/api/v1/hospitality/group-blocks/{$id}", ['name' => 'Late change'])->assertUnprocessable();
    $this->getJson('/api/v1/hospitality/group-blocks?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
});

test('desk staff pick up rooms but only managers cancel a block, and other companies see nothing', function (): void {
    $id = ($this->block)()->assertCreated()->json('data.id');

    verticalApi($this->desk, $this->tenant);
    $this->getJson("/api/v1/hospitality/group-blocks/{$id}")->assertOk();
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2])->assertCreated();
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/cancel")->assertForbidden();

    $other = verticalTenant();
    verticalApi(verticalMember($other, 'admin'), $other);
    $this->getJson("/api/v1/hospitality/group-blocks/{$id}")->assertNotFound();
});

test('the availability grid is bounded and ordered by room type', function (): void {
    ($this->grid)('2026-11-25', '2026-11-25')->assertUnprocessable()->assertJsonValidationErrors('to');
    ($this->grid)('2026-11-01', '2027-02-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    expect(array_column(($this->grid)()->assertOk()->json('data'), 'code'))->toBe(['DBL', 'SUI']);
});

test('the folio statement lists charges, payments and the balance of any stay', function (): void {
    $stay = $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Maria Rossi', 'room_type_id' => $this->double, 'check_in_date' => '2026-11-19', 'check_out_date' => '2026-11-21', 'adults' => 2])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/stays/{$stay}/check-in", ['city_tax_per_person_night' => 2])->assertOk();
    $extra = $this->postJson("/api/v1/hospitality/stays/{$stay}/charges", ['category' => 'extra', 'description' => 'Minibar', 'unit_amount' => 12.5])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/stays/{$stay}/charges", ['category' => 'restaurant', 'description' => 'Dinner', 'unit_amount' => 60])->assertCreated();
    $this->postJson("/api/v1/hospitality/stays/{$stay}/payments", ['amount' => 100, 'payment_method' => 'card'])->assertCreated();
    $this->postJson("/api/v1/hospitality/folio-entries/{$extra}/void")->assertOk();

    $statement = $this->getJson("/api/v1/hospitality/stays/{$stay}/folio-statement")->assertOk()->assertJsonCount(4, 'data.charges')->assertJsonCount(1, 'data.payments');
    expect($statement->json('data.totals'))->toMatchArray(['charges' => '268.00', 'payments' => '100.00', 'balance' => '168.00'])
        ->and($statement->json('data.totals.by_category'))->toMatchArray(['room' => '200.00', 'city_tax' => '8.00', 'restaurant' => '60.00'])
        ->and($statement->json('data.stay'))->toMatchArray(['number' => $statement->json('data.stay.number'), 'status' => 'checked_in', 'invoice_id' => null]);
});

test('a checked-out folio is invoiced once through the invoicing service with the chosen tax rates', function (): void {
    verticalLedger($this->tenant);
    verticalApi($this->manager, $this->tenant);
    $stay = $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Maria Rossi', 'room_type_id' => $this->double, 'check_in_date' => '2026-11-19', 'check_out_date' => '2026-11-21', 'adults' => 2])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/stays/{$stay}/check-in", ['city_tax_per_person_night' => 2])->assertOk();
    $this->postJson("/api/v1/hospitality/stays/{$stay}/charges", ['category' => 'restaurant', 'description' => 'Dinner', 'unit_amount' => 60])->assertCreated();
    $request = ['contact_id' => $this->customer->id, 'tax_rate' => '8.1', 'category_tax_rates' => ['room' => '3.8', 'city_tax' => '0']];

    $this->postJson("/api/v1/hospitality/stays/{$stay}/invoice", $request)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'checked-out'));
    $this->postJson("/api/v1/hospitality/stays/{$stay}/payments", ['amount' => 268, 'payment_method' => 'card'])->assertCreated();
    $this->postJson("/api/v1/hospitality/stays/{$stay}/check-out")->assertOk();

    $this->postJson("/api/v1/hospitality/stays/{$stay}/invoice", ['contact_id' => $this->customer->id])->assertUnprocessable()->assertJsonValidationErrors('tax_rate');
    $this->postJson("/api/v1/hospitality/stays/{$stay}/invoice", [...$request, 'category_tax_rates' => ['minibar' => '1']])->assertUnprocessable();
    $response = $this->postJson("/api/v1/hospitality/stays/{$stay}/invoice", $request)->assertCreated();

    $invoice = Invoice::query()->findOrFail($response->json('data.invoice_id'));
    expect((string) $invoice->contact_id)->toBe((string) $this->customer->id)
        ->and($invoice->items()->count())->toBe(4)
        ->and((float) $invoice->total_net)->toBe(268.0)
        ->and((float) $invoice->total_tax)->toBe(round(200 * 0.038 + 60 * 0.081, 2));
    $this->getJson("/api/v1/hospitality/stays/{$stay}/folio-statement")->assertJsonPath('data.stay.invoice_id', $invoice->id);
    $this->postJson("/api/v1/hospitality/stays/{$stay}/invoice", $request)->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'already invoiced'));

    verticalApi($this->desk, $this->tenant);
    $this->postJson("/api/v1/hospitality/stays/{$stay}/invoice", $request)->assertForbidden();
    expect(Accommodation::query()->count())->toBe(4);
});

test('the scheduled job releases the open blocks past their release date and leaves the others', function (): void {
    $past = ($this->block)()->assertCreated()->json('data.id');
    $future = ($this->block)(['name' => 'Conference', 'release_date' => '2026-11-24', 'rooms' => [['room_type_id' => $this->suite, 'rooms_blocked' => 1]]])->assertCreated()->json('data.id');

    $this->travelTo('2026-11-23 09:00:00');
    (new ReleaseExpiredGroupBlocksJob($this->tenant->id))->handle(app(TenantContext::class));

    $this->getJson("/api/v1/hospitality/group-blocks/{$past}")->assertJsonPath('data.status', 'released');
    $this->getJson("/api/v1/hospitality/group-blocks/{$future}")->assertJsonPath('data.status', 'open');
    expect(app(TenantContext::class)->runWithTenant($this->tenant, fn (): int => app(GroupBookingService::class)->releaseExpired()))->toBe(0);
});

test('a block can move to new dates unless picked up stays fall outside or the rooms are not free', function (): void {
    $id = ($this->block)()->assertCreated()->json('data.id');
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/stays", ['guest_name' => 'Guest', 'room_type_id' => $this->double, 'adults' => 2, 'check_in_date' => '2026-11-26', 'check_out_date' => '2026-11-27'])->assertCreated();

    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/dates", ['check_in_date' => '2026-11-27', 'check_out_date' => '2026-11-29'])
        ->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'outside the new dates'));
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/dates", ['check_in_date' => '2026-11-25', 'check_out_date' => '2026-11-25'])->assertUnprocessable()->assertJsonValidationErrors('check_out_date');
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/dates", ['check_in_date' => '2026-11-25', 'check_out_date' => '2026-11-27', 'release_date' => '2026-11-26'])->assertUnprocessable()->assertJsonValidationErrors('release_date');

    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/dates", ['check_in_date' => '2026-11-24', 'check_out_date' => '2026-11-29'])
        ->assertOk()->assertJsonPath('data.check_in_date', '2026-11-24')->assertJsonPath('data.check_out_date', '2026-11-29')->assertJsonPath('data.release_date', '2026-11-22');

    $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Walk In', 'room_type_id' => $this->double, 'check_in_date' => '2026-12-05', 'check_out_date' => '2026-12-08', 'adults' => 2])->assertCreated();
    $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Walk In 2', 'room_type_id' => $this->double, 'check_in_date' => '2026-12-05', 'check_out_date' => '2026-12-08', 'adults' => 2])->assertCreated();
    $this->postJson('/api/v1/hospitality/stays', ['guest_name' => 'Walk In 3', 'room_type_id' => $this->double, 'check_in_date' => '2026-12-05', 'check_out_date' => '2026-12-08', 'adults' => 2])->assertCreated();
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/dates", ['check_in_date' => '2026-12-05', 'check_out_date' => '2026-12-08', 'release_date' => '2026-12-01'])
        ->assertUnprocessable();

    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/cancel")->assertOk();
    $this->postJson("/api/v1/hospitality/group-blocks/{$id}/dates", ['check_in_date' => '2026-11-24', 'check_out_date' => '2026-11-26'])->assertUnprocessable();
});
