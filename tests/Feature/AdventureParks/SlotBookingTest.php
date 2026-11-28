<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/AdventureParksSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
});

it('refuses the booking that exceeds the stock of a size with slot_sold_out and frees the stock on a no-show', function (): void {
    apHarnesses($this, 'JUNIOR', 3);
    $slot = apSlot($this, '10:00', '12:00');

    $first = apBook($this, $slot, '2026-06-16', ['JUNIOR' => 2])->assertCreated()
        ->assertJsonPath('data.status', 'BOOKED')
        ->assertJsonPath('data.headcount', 2)
        ->assertJsonPath('data.waiver_signed', false);

    $this->getJson(AP_API.'/slots/availability?date=2026-06-16')->assertOk()
        ->assertJsonPath('data.slots.0.stock.JUNIOR.total', 3)
        ->assertJsonPath('data.slots.0.stock.JUNIOR.booked', 2)
        ->assertJsonPath('data.slots.0.stock.JUNIOR.remaining', 1);

    apBook($this, $slot, '2026-06-16', ['JUNIOR' => 2])->assertStatus(422)
        ->assertJsonPath('code', 'slot_sold_out')
        ->assertJsonPath('context.size', 'JUNIOR')
        ->assertJsonPath('context.requested', 2)
        ->assertJsonPath('context.remaining', 1);

    apBook($this, $slot, '2026-06-16', ['JUNIOR' => 1])->assertCreated();
    apBook($this, $slot, '2026-06-16', ['JUNIOR' => 1])->assertStatus(422)->assertJsonPath('code', 'slot_sold_out')->assertJsonPath('context.remaining', 0);

    $this->postJson(AP_API.'/tickets/'.$first->json('data.id').'/no-show')->assertOk()->assertJsonPath('data.status', 'NO_SHOW');

    apBook($this, $slot, '2026-06-16', ['JUNIOR' => 2])->assertCreated();
});

it('counts the harnesses held by the slots that overlap the buffer around the requested slot', function (): void {
    apHarnesses($this, 'ADULT', 4);
    $morning = apSlot($this, '10:00', '12:00');
    $overlapping = apSlot($this, '12:10', '14:00');
    $later = apSlot($this, '14:00', '16:00');

    apBook($this, $morning, '2026-06-16', ['ADULT' => 3])->assertCreated();

    $slots = collect($this->getJson(AP_API.'/slots/availability?date=2026-06-16')->assertOk()->json('data.slots'))->keyBy('id');

    expect($slots[$morning]['stock']['ADULT']['remaining'])->toBe(1)
        // 12:10 is inside the 15 minute buffer after the end of the morning slot
        ->and($slots[$overlapping]['stock']['ADULT']['remaining'])->toBe(1)
        ->and($slots[$later]['stock']['ADULT']['remaining'])->toBe(4);

    apBook($this, $overlapping, '2026-06-16', ['ADULT' => 2])->assertStatus(422)->assertJsonPath('code', 'slot_sold_out');
    apBook($this, $later, '2026-06-16', ['ADULT' => 4])->assertCreated();
});

it('leaves out of the stock the harnesses that are blocked, in maintenance or past their life', function (): void {
    apHarnesses($this, 'XL', 2);
    apHarnesses($this, 'XL', 1, 'OLD', ['manufacturer_max_lifespan_date' => '2026-06-10']);
    apHarnesses($this, 'XL', 1, 'LATE', ['next_inspection_due_date' => '2026-06-14']);
    $slot = apSlot($this);

    $this->getJson(AP_API.'/slots/availability?date=2026-06-16')->assertOk()
        ->assertJsonPath('data.slots.0.stock.XL.total', 2);

    // a harness whose life ends on 20 June is good on the 16th but not on the 22nd
    apHarnesses($this, 'XL', 1, 'EOL', ['manufacturer_max_lifespan_date' => '2026-06-20']);

    $this->getJson(AP_API.'/slots/availability?date=2026-06-16')->assertJsonPath('data.slots.0.stock.XL.total', 3);
    $this->getJson(AP_API.'/slots/availability?date=2026-06-22')->assertJsonPath('data.slots.0.stock.XL.total', 2);

    apBook($this, $slot, '2026-06-16', ['XL' => 3])->assertCreated();
});

it('hands the wristband colours of the palette to the slots in turn and stamps the colour on the ticket', function (): void {
    apHarnesses($this, 'ADULT', 2);
    $first = apSlot($this, '09:00', '10:30');
    $second = apSlot($this, '11:00', '12:30');
    $explicit = apSlot($this, '14:00', '15:30', ['wristband_color' => 'PINK']);

    $colours = collect($this->getJson(AP_API.'/slots')->assertOk()->json('data'))->pluck('wristband_color', 'id');

    expect($colours[$first])->toBe('RED')->and($colours[$second])->toBe('BLUE')->and($colours[$explicit])->toBe('PINK');

    apBook($this, $second, '2026-06-16', ['ADULT' => 1])->assertCreated()
        ->assertJsonPath('data.wristband_color', 'BLUE')
        ->assertJsonPath('data.slot.wristband_color', 'BLUE');
});

it('does not sell a slot on a weekday it does not run and keeps the bookable sizes to the four harness sizes', function (): void {
    apHarnesses($this, 'ADULT', 2);
    $mondays = apSlot($this, '10:00', '12:00', ['weekdays' => [1]]);

    // 16 June 2026 is a Tuesday, 22 June a Monday
    apBook($this, $mondays, '2026-06-16', ['ADULT' => 1])->assertStatus(422)->assertJsonPath('code', 'slot_closed');
    apBook($this, $mondays, '2026-06-22', ['ADULT' => 1])->assertCreated();
    apBook($this, $mondays, '2026-06-22', ['UNIVERSAL' => 1])->assertStatus(422)->assertJsonValidationErrors(['lines.0.size']);

    $this->getJson(AP_API.'/slots/availability?date=2026-06-16')->assertJsonPath('data.slots.0.is_open', false);
});

it('finds a ticket by booking code or public token and lists the tickets of a day', function (): void {
    apHarnesses($this, 'ADULT', 2);
    $slot = apSlot($this);
    $ticket = apBook($this, $slot, '2026-06-16', ['ADULT' => 2])->assertCreated()->json('data');

    expect($ticket['booking_code'])->toStartWith('PK-260616-')
        ->and($ticket['waiver_url_path'])->toContain($ticket['public_token']);

    $this->getJson(AP_API.'/tickets/by-code/'.$ticket['booking_code'])->assertOk()->assertJsonPath('data.id', $ticket['id']);
    $this->getJson(AP_API.'/tickets/by-code/'.$ticket['public_token'])->assertOk()->assertJsonPath('data.id', $ticket['id']);
    $this->getJson(AP_API.'/tickets/by-code/NOPE')->assertNotFound()->assertJsonPath('code', 'ticket_not_found');
    $this->getJson(AP_API.'/tickets?date=2026-06-16&status=BOOKED')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(AP_API.'/tickets?date=2026-06-17')->assertOk()->assertJsonCount(0, 'data');
});

it('keeps the park of a company away from the others', function (): void {
    apHarnesses($this, 'ADULT', 2);
    $slot = apSlot($this);
    $ticket = apBook($this, $slot, '2026-06-16', ['ADULT' => 1])->assertCreated()->json('data');
    $serial = apHarnesses($this, 'JUNIOR', 1)[0];
    $ppe = collect($this->getJson(AP_API.'/ppe')->json('data'))->firstWhere('serial_code', $serial);

    billingActor($this, 'accountant', $this->otherTenant);

    $this->getJson(AP_API.'/tickets/'.$ticket['id'])->assertNotFound();
    $this->getJson(AP_API.'/ppe/'.$ppe['id'])->assertNotFound();
    $this->getJson(AP_API.'/tickets?date=2026-06-16')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(AP_API.'/ppe')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(AP_API.'/slots')->assertOk()->assertJsonCount(0, 'data');
    apBook($this, $slot, '2026-06-16', ['ADULT' => 1])->assertStatus(422)->assertJsonValidationErrors(['slot_id']);
});

it('lets the dressing desk book but keeps the configuration to the managers', function (string $role, int $read, int $book, int $configure): void {
    billingActor($this, 'accountant');
    apHarnesses($this, 'ADULT', 1);
    $slot = apSlot($this);

    billingActor($this, $role);

    $this->getJson(AP_API.'/dashboard?date=2026-06-16')->assertStatus($read);
    apBook($this, $slot, '2026-06-16', ['ADULT' => 1])->assertStatus($book);
    $this->postJson(AP_API.'/slots', ['start_time' => '15:00', 'end_time' => '16:00'])->assertStatus($configure);
})->with([
    'owner' => ['owner', 200, 201, 201],
    'manager' => ['manager', 200, 201, 201],
    'operator' => ['operator', 200, 201, 403],
    'read-only accountant' => ['accountant_readonly', 200, 403, 403],
    'sales' => ['sales', 403, 403, 403],
]);
