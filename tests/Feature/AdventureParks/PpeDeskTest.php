<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/AdventureParksSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    apSetUp($this);
    $this->slot = apSlot($this, '10:00', '12:00');
});

/**
 * @return array<string, mixed>
 */
function apPpeBySerial(object $test, string $serial): array
{
    return collect($test->getJson(AP_API.'/ppe')->assertOk()->json('data'))->firstWhere('serial_code', $serial);
}

it('refuses to hand out equipment before the waiver covers every participant', function (): void {
    apHarnesses($this, 'ADULT', 1);
    $ticket = apBook($this, $this->slot, '2026-06-16', ['ADULT' => 1])->assertCreated()->json('data');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => (string) Str::uuid(), 'barcode' => 'AD-000001'])
        ->assertStatus(422)->assertJsonPath('code', 'waiver_missing');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/start-course')->assertStatus(422)->assertJsonPath('code', 'invalid_transition');
});

it('runs the whole lifecycle: sign, equip every participant, send on the course and take the equipment back', function (): void {
    apHarnesses($this, 'ADULT', 1);
    apHarnesses($this, 'JUNIOR', 1);
    $ticket = apSignedTicket($this, $this->slot, '2026-06-16', ['ADULT' => 1, 'JUNIOR' => 1], 1);

    expect($ticket['status'])->toBe('WAIVER_SIGNED')->and($ticket['waiver_signed'])->toBeTrue()->and($ticket['participants'])->toHaveCount(2);

    $people = apParticipants($ticket);
    $sizes = collect($ticket['participants'])->pluck('size', 'full_name');
    expect($sizes['Mario Rossi'])->toBe('ADULT')->and($sizes['Figlio 1'])->toBe('JUNIOR');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/briefing', ['instructor_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.briefing_completed', true);

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000001'])
        ->assertOk()->assertJsonPath('data.status', 'WAIVER_SIGNED')->assertJsonPath('data.wristband_color', 'RED');

    expect(apPpeBySerial($this, 'AD-000001')['status'])->toBe('IN_USE');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Figlio 1'], 'barcode' => 'JU-000001'])
        ->assertOk()->assertJsonPath('data.status', 'EQUIPPED');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/start-course')->assertOk()->assertJsonPath('data.status', 'ON_COURSE');
    $this->getJson(AP_API.'/dashboard?date=2026-06-16')->assertOk()->assertJsonPath('data.on_course_count', 2);

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/return-ppe', ['usage_hours' => 1.5])
        ->assertOk()->assertJsonPath('data.status', 'RETURNED');

    $adult = apPpeBySerial($this, 'AD-000001');
    expect($adult['status'])->toBe('AVAILABLE')->and($adult['rental_cycle_count'])->toBe(1)->and($adult['cumulative_usage_hours'])->toBe(1.5);
});

it('returns only the items scanned and defaults the usage to the length of the slot', function (): void {
    apHarnesses($this, 'ADULT', 2);
    $ticket = apSignedTicket($this, $this->slot, '2026-06-16', ['ADULT' => 2], 1);
    // the minor of the signed waiver is JUNIOR-sized, so book an ADULT/ADULT group the simple way: size follows the booked lines
    $people = apParticipants($ticket);

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000001'])->assertOk();
    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Figlio 1'], 'barcode' => 'AD-000002'])->assertOk()->assertJsonPath('data.status', 'EQUIPPED');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/return-ppe', ['barcodes' => ['AD-000001']])->assertOk()->assertJsonPath('data.status', 'EQUIPPED');
    expect(apPpeBySerial($this, 'AD-000001')['cumulative_usage_hours'])->toBe(2.0)
        ->and(apPpeBySerial($this, 'AD-000002')['status'])->toBe('IN_USE');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/return-ppe', ['barcodes' => ['AD-000001']])->assertStatus(422)->assertJsonPath('code', 'ppe_not_found');
    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/return-ppe')->assertOk()->assertJsonPath('data.status', 'RETURNED');
});

it('refuses an unknown barcode, a size that does not fit and a second harness for the same visitor', function (): void {
    apHarnesses($this, 'ADULT', 2);
    apHarnesses($this, 'JUNIOR', 1);
    $ticket = apSignedTicket($this, $this->slot, '2026-06-16', ['ADULT' => 1, 'JUNIOR' => 1], 1);
    $people = apParticipants($ticket);
    $url = AP_API.'/tickets/'.$ticket['id'].'/assign-ppe';

    $this->postJson($url, ['participant_id' => $people['Mario Rossi'], 'barcode' => 'NOPE-1'])->assertStatus(422)->assertJsonPath('code', 'ppe_not_found');
    $this->postJson($url, ['participant_id' => $people['Mario Rossi'], 'barcode' => 'JU-000001'])->assertStatus(422)
        ->assertJsonPath('code', 'ppe_size_mismatch')->assertJsonPath('context.ppe_size', 'JUNIOR')->assertJsonPath('context.participant_size', 'ADULT');
    $this->postJson($url, ['participant_id' => (string) Str::uuid(), 'barcode' => 'AD-000001'])->assertNotFound()->assertJsonPath('code', 'participant_not_found');

    $this->postJson($url, ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000001'])->assertOk();
    $this->postJson($url, ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000002'])->assertStatus(422)->assertJsonPath('code', 'ppe_already_assigned');
    $this->postJson($url, ['participant_id' => $people['Figlio 1'], 'barcode' => 'AD-000001'])->assertStatus(422)->assertJsonPath('code', 'ppe_blocked')->assertJsonPath('context.reason', 'STATUS');
});

it('blocks a harness whose manufacturer lifespan ran out and keeps it blocked', function (): void {
    apHarnesses($this, 'ADULT', 1, 'AD', ['manufacturer_max_lifespan_date' => '2026-06-30']);
    $ticket = apSignedTicket($this, $this->slot, '2026-06-16', ['ADULT' => 1], 0);
    $people = apParticipants($ticket);

    // the day after the lifespan ended the item is still AVAILABLE in the database until somebody tries to use it
    $this->travelTo('2026-07-02 09:00:00');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000001'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'ppe_blocked')
        ->assertJsonPath('context.reason', 'LIFESPAN_EXPIRED')
        ->assertJsonPath('context.serial_code', 'AD-000001')
        ->assertJsonPath('context.category', 'HARNESS');

    $ppe = apPpeBySerial($this, 'AD-000001');
    expect($ppe['status'])->toBe('EXPIRED_BLOCKED')->and($ppe['blocked_reason'])->toBe('LIFESPAN_EXPIRED')->and($ppe['is_assignable'])->toBeFalse();

    // a passed inspection does not revive an item past its life
    $this->postJson(AP_API.'/ppe/'.$ppe['id'].'/inspections', [
        'inspection_type' => 'ANNUAL_EN365', 'outcome' => 'PASS', 'inspected_on' => '2026-07-02', 'inspector_license_number' => 'CP-1234',
    ])->assertCreated();
    expect(apPpeBySerial($this, 'AD-000001')['status'])->toBe('EXPIRED_BLOCKED');
});

it('blocks a harness whose annual EN 365 inspection is overdue until a passed inspection moves the due date', function (): void {
    apHarnesses($this, 'ADULT', 1, 'AD', ['next_inspection_due_date' => '2026-06-14']);
    apHarnesses($this, 'ADULT', 1, 'GOOD');
    $ticket = apSignedTicket($this, $this->slot, '2026-06-16', ['ADULT' => 1], 0);
    $people = apParticipants($ticket);
    $ppe = apPpeBySerial($this, 'AD-000001');

    expect($ppe['status'])->toBe('EXPIRED_BLOCKED')->and($ppe['blocked_reason'])->toBe('INSPECTION_OVERDUE');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000001'])
        ->assertStatus(422)->assertJsonPath('code', 'ppe_blocked')->assertJsonPath('context.reason', 'INSPECTION_OVERDUE');

    $this->postJson(AP_API.'/ppe/'.$ppe['id'].'/inspections', ['inspection_type' => 'ANNUAL_EN365', 'outcome' => 'PASS', 'inspected_on' => '2026-06-15'])
        ->assertStatus(422)->assertJsonValidationErrors(['inspector_license_number']);

    $this->postJson(AP_API.'/ppe/'.$ppe['id'].'/inspections', [
        'inspection_type' => 'ANNUAL_EN365', 'outcome' => 'PASS', 'inspected_on' => '2026-06-15', 'inspector_name' => 'Ing. Bianchi', 'inspector_license_number' => 'CP-1234',
        'checklist' => [['key' => 'webbing_wear', 'ok' => true], ['key' => 'stitching_integrity', 'ok' => true]],
    ])->assertCreated()->assertJsonPath('data.next_due_date', '2027-06-15')->assertJsonPath('data.outcome', 'PASS');

    $unlocked = apPpeBySerial($this, 'AD-000001');
    expect($unlocked['status'])->toBe('AVAILABLE')->and($unlocked['next_inspection_due_date'])->toBe('2027-06-15')->and($unlocked['last_inspection_date'])->toBe('2026-06-15');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => $people['Mario Rossi'], 'barcode' => 'AD-000001'])->assertOk()->assertJsonPath('data.status', 'EQUIPPED');
    $this->getJson(AP_API.'/ppe/'.$ppe['id'].'/inspections')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.inspector_license_number', 'CP-1234');
});

it('sends an item to maintenance after a failed inspection and takes it out of the stock', function (): void {
    apHarnesses($this, 'ADULT', 2);
    $ppe = apPpeBySerial($this, 'AD-000001');

    $this->postJson(AP_API.'/ppe/'.$ppe['id'].'/inspections', ['inspection_type' => 'DAILY_VISUAL', 'outcome' => 'FAIL', 'inspected_on' => '2026-06-15', 'notes' => 'Frayed webbing'])->assertCreated();

    expect(apPpeBySerial($this, 'AD-000001')['status'])->toBe('MAINTENANCE_REQUIRED');
    $this->getJson(AP_API.'/slots/availability?date=2026-06-16')->assertJsonPath('data.slots.0.stock.ADULT.total', 1);

    // a passed daily visual check does not unlock it; only a passed annual inspection does
    $this->postJson(AP_API.'/ppe/'.$ppe['id'].'/inspections', ['inspection_type' => 'DAILY_VISUAL', 'outcome' => 'PASS', 'inspected_on' => '2026-06-15'])->assertCreated();
    expect(apPpeBySerial($this, 'AD-000001')['status'])->toBe('MAINTENANCE_REQUIRED');

    $this->postJson(AP_API.'/ppe/'.$ppe['id'].'/inspections', ['inspection_type' => 'ANNUAL_EN365', 'outcome' => 'PASS', 'inspected_on' => '2026-06-15', 'inspector_license_number' => 'CP-9'])->assertCreated();
    expect(apPpeBySerial($this, 'AD-000001')['status'])->toBe('AVAILABLE');
});

it('registers batches with sequential serials, filters and searches the register and refuses duplicates', function (): void {
    apHarnesses($this, 'ADULT', 3);
    apHarnesses($this, 'ADULT', 2);
    apHarnesses($this, 'BABY', 1, 'BB');

    $serials = collect($this->getJson(AP_API.'/ppe?size=ADULT')->assertOk()->json('data'))->pluck('serial_code')->all();
    expect($serials)->toBe(['AD-000001', 'AD-000002', 'AD-000003', 'AD-000004', 'AD-000005']);

    $this->getJson(AP_API.'/ppe?search=bb-0000')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(AP_API.'/ppe?category=HARNESS&status=AVAILABLE')->assertOk()->assertJsonCount(6, 'data');

    $payload = ['serial_code' => 'AD-000001', 'category' => 'HELMET', 'size' => 'UNIVERSAL', 'manufacturer_max_lifespan_date' => '2030-01-01'];
    $this->postJson(AP_API.'/ppe', $payload)->assertStatus(422)->assertJsonValidationErrors(['serial_code']);
    $this->postJson(AP_API.'/ppe', array_merge($payload, ['serial_code' => 'HEL-1']))->assertCreated()->assertJsonPath('data.category', 'HELMET')->assertJsonPath('data.status', 'AVAILABLE');
});

it('lists the lifespan and inspection alerts and the daily opening check', function (): void {
    apHarnesses($this, 'ADULT', 1, 'AD', ['manufacturer_max_lifespan_date' => '2026-07-01', 'next_inspection_due_date' => '2026-06-20']);
    apHarnesses($this, 'ADULT', 1, 'OV', ['next_inspection_due_date' => '2026-06-01']);
    apHarnesses($this, 'ADULT', 1, 'FINE');

    $alerts = $this->getJson(AP_API.'/ppe/alerts')->assertOk()
        ->assertJsonPath('data.counts.lifespan_expiring', 1)
        ->assertJsonPath('data.counts.inspection_due_soon', 1)
        ->assertJsonPath('data.counts.inspection_overdue', 1)
        ->assertJsonPath('data.counts.lifespan_expired', 0)
        ->assertJsonPath('data.daily_check_done_today', false)
        ->json('data.items');

    expect(collect($alerts)->pluck('type')->sort()->values()->all())->toBe(['INSPECTION_DUE_SOON', 'INSPECTION_OVERDUE', 'LIFESPAN_EXPIRING']);

    $this->getJson(AP_API.'/courses/daily-opening-check?date=2026-06-15')->assertOk()->assertJsonPath('data', null);

    $this->postJson(AP_API.'/courses/daily-opening-check', [
        'check_date' => '2026-06-15', 'checked_by_name' => 'Luca Guida', 'signature' => 'data:image/png;base64,AAAA',
        'items' => [['key' => 'cables_tension', 'ok' => true], ['key' => 'safety_nets', 'ok' => false, 'notes' => 'Net 3 torn']],
    ])->assertCreated()->assertJsonPath('data.outcome', 'FAIL');

    $this->postJson(AP_API.'/courses/daily-opening-check', [
        'check_date' => '2026-06-15', 'checked_by_name' => 'Luca Guida', 'signature' => 'data:image/png;base64,AAAA',
        'items' => [['key' => 'unknown_item', 'ok' => true]],
    ])->assertStatus(422)->assertJsonValidationErrors(['items.0.key']);

    $this->postJson(AP_API.'/courses/daily-opening-check', [
        'check_date' => '2026-06-14', 'checked_by_name' => 'Luca Guida', 'signature' => 'data:image/png;base64,AAAA',
        'items' => [['key' => 'cables_tension', 'ok' => true], ['key' => 'safety_nets', 'ok' => true]],
    ])->assertCreated()->assertJsonPath('data.outcome', 'PASS');
    $this->getJson(AP_API.'/courses/daily-opening-check?date=2026-06-14')->assertOk()->assertJsonPath('data.outcome', 'PASS');

    $this->getJson(AP_API.'/ppe/alerts')->assertJsonPath('data.daily_check_done_today', true);
    $this->getJson(AP_API.'/dashboard?date=2026-06-15')->assertOk()
        ->assertJsonPath('data.alerts.daily_check_done', true)
        ->assertJsonPath('data.alerts.inspection_overdue', 1)
        ->assertJsonPath('data.daily_check.outcome', 'FAIL');
});

it('does not delete an item that is out with a visitor', function (): void {
    apHarnesses($this, 'ADULT', 1);
    $ticket = apSignedTicket($this, $this->slot, '2026-06-16', ['ADULT' => 1], 0);
    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/assign-ppe', ['participant_id' => apParticipants($ticket)['Mario Rossi'], 'barcode' => 'AD-000001'])->assertOk();
    $ppe = apPpeBySerial($this, 'AD-000001');

    $this->deleteJson(AP_API.'/ppe/'.$ppe['id'])->assertStatus(422)->assertJsonPath('code', 'ppe_in_use');

    $this->postJson(AP_API.'/tickets/'.$ticket['id'].'/return-ppe')->assertOk();
    $this->deleteJson(AP_API.'/ppe/'.$ppe['id'])->assertOk();
    $this->getJson(AP_API.'/ppe/'.$ppe['id'])->assertNotFound();
});
