<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OliveMillAndGrainMill\Models\OliveDelivery;

require_once __DIR__.'/OliveMillSupport.php';

uses(RefreshDatabase::class);

test('a delivery is booked with the weighbridge reading and the campaign lot code', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, [
        'weighing' => ['gross_weight_kg' => 5640, 'tare_kg' => 400, 'crate_count' => 100, 'crate_unit_tare_kg' => 2, 'weighbridge_ticket' => 'TKT-77'],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.lot_code', '2027-OL-00001')
        ->assertJsonPath('data.campaign_year', 2027)
        ->assertJsonPath('data.status', 'WEIGHED')
        ->assertJsonPath('data.net_weight_kg', '5040.000')
        ->assertJsonPath('data.weighbridge_ticket', 'TKT-77')
        ->assertJsonPath('data.farmer_name', 'Azienda Agricola Rossi')
        ->assertJsonPath('data.sms_recipient_phone', '+393331234567');

    omDelivery($this);

    expect(OliveDelivery::query()->orderBy('lot_code')->pluck('lot_code')->all())->toBe(['2027-OL-00001', '2027-OL-00002']);
});

test('a booking without a reading waits for the weighbridge', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['weighing' => null]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'BOOKED')
        ->assertJsonPath('data.net_weight_kg', null);
});

test('the olives of January and February belong to the campaign of the previous year', function (): void {
    omSetUp($this);
    $this->travelTo('2028-01-15 09:00:00');

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['delivered_at' => '2028-01-15 09:00:00']))
        ->assertCreated()
        ->assertJsonPath('data.campaign_year', 2027)
        ->assertJsonPath('data.lot_code', '2027-OL-00001');
});

test('grain deliveries have their own lot series', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['commodity' => 'CEREALI', 'grain_type' => 'durum wheat', 'certification' => 'CONVENZIONALE']))
        ->assertCreated()
        ->assertJsonPath('data.lot_code', '2027-CE-00001')
        ->assertJsonPath('data.grain_type', 'durum wheat');
});

test('a third-party delivery needs the farmer and an own-account one does not', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['farmer_contact_id' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('farmer_contact_id');

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['processing_mode' => 'CONTO_PROPRIO', 'farmer_contact_id' => null]))
        ->assertCreated()
        ->assertJsonPath('data.farmer_contact_id', null);
});

test('the farmer of another company cannot be named', function (): void {
    omSetUp($this);
    $stranger = omFarmer($this, ['tenant_id' => $this->otherTenant->id]);

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['farmer_contact_id' => $stranger->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('farmer_contact_id');
});

test('two bookings cannot overlap on the same line', function (): void {
    omSetUp($this);
    $slot = ['hopper_line' => 'L1', 'estimated_duration_minutes' => 60, 'weighing' => null];

    omDelivery($this, $slot + ['scheduled_at' => '2027-11-10 14:00:00']);

    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, $slot + ['scheduled_at' => '2027-11-10 14:30:00']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'slot_conflict');

    // Right after the first one, and on another line, the slot is free.
    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, $slot + ['scheduled_at' => '2027-11-10 15:00:00']))->assertCreated();
    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['hopper_line' => 'L2', 'estimated_duration_minutes' => 60, 'weighing' => null, 'scheduled_at' => '2027-11-10 14:30:00']))->assertCreated();
});

test('a delivery can move to another slot without clashing with itself', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this, ['hopper_line' => 'L1', 'estimated_duration_minutes' => 60, 'weighing' => null, 'scheduled_at' => '2027-11-10 14:00:00']);

    $this->putJson(OM_API.'/olive-deliveries/'.$delivery->id, omDeliveryPayload($this, ['hopper_line' => 'L1', 'estimated_duration_minutes' => 60, 'weighing' => null, 'scheduled_at' => '2027-11-10 14:15:00', 'cultivar' => 'Leccino']))
        ->assertOk()
        ->assertJsonPath('data.cultivar', 'Leccino');
});

test('the weighbridge endpoint records gross, tare, crates and the scale payload', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this, ['weighing' => null]);

    $this->postJson(OM_API.'/olive-deliveries/'.$delivery->id.'/weigh', [
        'gross_weight_kg' => 5640, 'tare_kg' => 400, 'crate_count' => 100, 'crate_unit_tare_kg' => 2,
        'weighbridge_ticket' => 'TKT-9', 'scale_payload' => ['device' => 'scale-1', 'raw' => 5640], 'scale_read_at' => '2027-11-10 09:58:00',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'WEIGHED')
        ->assertJsonPath('data.net_weight_kg', '5040.000')
        ->assertJsonPath('data.scale_payload.device', 'scale-1');

    // A new reading replaces the previous one while the lot is not in the hopper yet.
    $this->postJson(OM_API.'/olive-deliveries/'.$delivery->id.'/weigh', ['gross_weight_kg' => 5000, 'tare_kg' => 400])
        ->assertOk()
        ->assertJsonPath('data.net_weight_kg', '4600.000');
});

test('a reading whose net weight is not positive is refused', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this, ['weighing' => null]);

    $this->postJson(OM_API.'/olive-deliveries/'.$delivery->id.'/weigh', ['gross_weight_kg' => 300, 'tare_kg' => 200, 'crate_count' => 100, 'crate_unit_tare_kg' => 2])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'net_weight_not_positive');
});

test('a lot in the hopper can neither be weighed again nor changed nor cancelled', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this);
    omStartCycle($this, $delivery);

    $this->postJson(OM_API.'/olive-deliveries/'.$delivery->id.'/weigh', ['gross_weight_kg' => 5000, 'tare_kg' => 400])->assertUnprocessable()->assertJsonPath('code', 'delivery_not_weighable');
    $this->putJson(OM_API.'/olive-deliveries/'.$delivery->id, omDeliveryPayload($this, ['weighing' => null]))->assertUnprocessable()->assertJsonPath('code', 'delivery_locked');
    $this->deleteJson(OM_API.'/olive-deliveries/'.$delivery->id)->assertStatus(409)->assertJsonPath('code', 'delivery_locked');
});

test('a booked delivery can be cancelled and leaves the list', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this, ['weighing' => null]);

    $this->deleteJson(OM_API.'/olive-deliveries/'.$delivery->id)->assertOk();

    $this->getJson(OM_API.'/olive-deliveries')->assertOk()->assertJsonCount(0, 'data');
});

test('the list filters by status, commodity and lot', function (): void {
    omSetUp($this);
    omDelivery($this);
    omDelivery($this, ['weighing' => null, 'cultivar' => 'Ogliarola']);
    omDelivery($this, ['commodity' => 'CEREALI', 'certification' => 'CONVENZIONALE']);

    $this->getJson(OM_API.'/olive-deliveries?status=BOOKED')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(OM_API.'/olive-deliveries?commodity=CEREALI')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lot_code', '2027-CE-00001');
    $this->getJson(OM_API.'/olive-deliveries?search=ogliarola')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    $this->getJson(OM_API.'/olive-deliveries?campaign_year=2027')->assertOk()->assertJsonCount(3, 'data');
});

test('the SMS reminder goes to the phone of the farmer with the lead time', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this, ['scheduled_at' => '2027-11-10 11:00:00', 'hopper_line' => 'L1']);

    $this->postJson(OM_API.'/olive-deliveries/'.$delivery->id.'/send-sms-reminder')
        ->assertOk()
        ->assertJsonPath('data.sms_status', 'SENT');

    expect($this->sms->sent)->toHaveCount(1)
        ->and($this->sms->sent[0][0])->toBe('+393331234567')
        ->and($this->sms->sent[0][1])->toContain('45')
        ->and($delivery->refresh()->sms_reminder_sent_at)->not->toBeNull();
});

test('the reminder needs a phone number and reports a refusal of the gateway', function (): void {
    omSetUp($this);
    $noPhone = omFarmer($this, ['phone' => null, 'company_name' => 'Senza telefono']);
    $delivery = omDelivery($this, ['farmer_contact_id' => $noPhone->id, 'sms_recipient_phone' => null]);

    $this->postJson(OM_API.'/olive-deliveries/'.$delivery->id.'/send-sms-reminder')->assertUnprocessable()->assertJsonPath('code', 'phone_missing');

    $reachable = omDelivery($this);
    $this->sms->fail = true;

    $this->postJson(OM_API.'/olive-deliveries/'.$reachable->id.'/send-sms-reminder')->assertStatus(502)->assertJsonPath('code', 'sms_failed');
    expect($reachable->refresh()->sms_status)->toBe('FAILED')->and($reachable->sms_reminder_sent_at)->toBeNull();
});

test('the scheduler reminds each lot once when its slot is within the lead time', function (): void {
    omSetUp($this);
    omDelivery($this, ['scheduled_at' => '2027-11-10 10:30:00', 'hopper_line' => 'L1']);
    omDelivery($this, ['scheduled_at' => '2027-11-10 16:00:00', 'hopper_line' => 'L2']);

    $this->artisan('olive-mill:send-reminders')->assertSuccessful();

    expect($this->sms->sent)->toHaveCount(1);

    $this->artisan('olive-mill:send-reminders')->assertSuccessful();

    expect($this->sms->sent)->toHaveCount(1);
});

test('the milling schedule groups the bookings by line', function (): void {
    omSetUp($this);
    omDelivery($this, ['scheduled_at' => '2027-11-10 14:00:00', 'hopper_line' => 'L1', 'weighing' => null]);
    omDelivery($this, ['scheduled_at' => '2027-11-11 09:00:00', 'hopper_line' => 'L1', 'weighing' => null]);
    omDelivery($this, ['scheduled_at' => '2027-11-10 15:00:00', 'hopper_line' => 'L2', 'weighing' => null]);

    $response = $this->getJson(OM_API.'/milling-schedule?from=2027-11-10&to=2027-11-12')->assertOk()->assertJsonCount(2, 'data.lines');

    expect(collect($response->json('data.lines'))->firstWhere('line', 'L1')['bookings'])->toHaveCount(2);

    $this->getJson(OM_API.'/milling-schedule?from=2027-11-10&to=2027-11-10&line=L2')->assertOk()->assertJsonCount(1, 'data.lines');
});

test('the data of another company is not reachable', function (): void {
    omSetUp($this);
    $delivery = omDelivery($this);

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson(OM_API.'/olive-deliveries/'.$delivery->id)->assertNotFound();
    $this->getJson(OM_API.'/olive-deliveries')->assertOk()->assertJsonCount(0, 'data');
});

test('a read-only member lists the deliveries but cannot book', function (): void {
    omSetUp($this, 'accountant_readonly');

    $this->getJson(OM_API.'/olive-deliveries')->assertOk();
    $this->postJson(OM_API.'/olive-deliveries', omDeliveryPayload($this, ['weighing' => null]))->assertForbidden();
});
