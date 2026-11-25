<?php

declare(strict_types=1);

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PassengerTransport\Models\PassengerBooking;

require_once __DIR__.'/PassengerTransportSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ptrSetUp($this);
});

it('creates a cross-border booking with the kilometres by country and a progressive code', function (): void {
    $first = $this->postJson('/api/v1/passenger-transport/bookings', ptrBookingPayload($this))->assertCreated();
    $second = $this->postJson('/api/v1/passenger-transport/bookings', ptrBookingPayload($this))->assertCreated();

    expect($first->json('data.booking_code'))->toMatch('/^BK-\d{4}-00001$/')
        ->and($second->json('data.booking_code'))->toMatch('/^BK-\d{4}-00002$/')
        ->and($first->json('data.status'))->toBe('draft')
        ->and((float) $first->json('data.it_territory_km'))->toBeGreaterThan(0)
        ->and((float) $first->json('data.ch_territory_km'))->toBeGreaterThan(0)
        ->and(round((float) $first->json('data.it_territory_km') + (float) $first->json('data.ch_territory_km'), 2))->toBe((float) $first->json('data.total_distance_km'))
        ->and($first->json('data.border_crossing_point'))->toBe('chiasso_brogeda')
        ->and($first->json('data.cross_border'))->toBeTrue()
        ->and($first->json('data.estimated_dropoff_datetime'))->not->toBeNull();

    $this->getJson('/api/v1/passenger-transport/bookings')->assertOk()->assertJsonCount(2, 'data');
});

it('picks the nearest crossing when none is chosen', function (): void {
    $data = ptrBooking($this, ['border_crossing_point' => 'none']);

    expect($data['border_crossing_point'])->not->toBe('none');
});

it('prices a cross-border fare without saving it: 10 percent on the Italian share, the Swiss leg outside the territory', function (): void {
    $response = $this->postJson('/api/v1/passenger-transport/bookings/quote', ptrBookingPayload($this, ['agreed_price_net' => '200.00', 'hotel_concierge_commission_rate' => '10']))->assertOk();
    $lines = $response->json('data.vat.lines');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['kind'])->toBe('it_domestic')
        ->and($lines[0]['rate'])->toBe('10.00')
        ->and($lines[1]['kind'])->toBe('it_foreign_leg')
        ->and($lines[1]['nature'])->toBe('N2.1')
        ->and($response->json('data.vat.total_net'))->toBe('200.00')
        ->and($response->json('data.hotel_concierge_commission_amount'))->toBe('20.00')
        ->and(PassengerBooking::query()->count())->toBe(0);
});

it('uses the Swiss VAT rules for a Swiss company', function (): void {
    ptrSetUp($this, 'CH');
    $response = $this->postJson('/api/v1/passenger-transport/bookings/quote', ptrBookingPayload($this, [
        'pickup_address' => 'Zürich Flughafen', 'pickup_lat' => 47.4647, 'pickup_lng' => 8.5492, 'pickup_country_code' => 'CH',
        'dropoff_address' => 'Milano', 'dropoff_lat' => 45.4642, 'dropoff_lng' => 9.19, 'dropoff_country_code' => 'IT',
        'agreed_price_net' => '700.00',
    ]))->assertOk();
    $kinds = array_column($response->json('data.vat.lines'), 'kind');

    expect($kinds)->toBe(['ch_domestic', 'ch_foreign_leg'])
        ->and($response->json('data.vat.lines.0.rate'))->toBe('8.1');
});

it('applies the Art. 23 exemption to the whole Swiss fare when requested', function (): void {
    ptrSetUp($this, 'CH');
    $response = $this->postJson('/api/v1/passenger-transport/bookings/quote', ptrBookingPayload($this, ['vat_exemption_art23' => true]))->assertOk();

    expect(array_column($response->json('data.vat.lines'), 'kind'))->toBe(['ch_exempt_art23'])
        ->and($response->json('data.vat.total_tax'))->toBe('0.00');
});

it('refuses a customer of another company and a missing fare', function (): void {
    $foreign = treasuryInTenant($this->otherTenant, fn (): Contact => Contact::query()->create(['company_name' => 'Altrui', 'type' => 'customer', 'country' => 'IT']));

    $this->postJson('/api/v1/passenger-transport/bookings', ptrBookingPayload($this, ['ordering_customer_id' => $foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('ordering_customer_id');
    $this->postJson('/api/v1/passenger-transport/bookings', array_diff_key(ptrBookingPayload($this), ['agreed_price_net' => true]))->assertUnprocessable()->assertJsonValidationErrors('agreed_price_net');
});

it('recomputes the kilometres and the commission when the route or the fare change', function (): void {
    $booking = ptrBooking($this, ['hotel_concierge_commission_rate' => '10']);

    $updated = $this->putJson('/api/v1/passenger-transport/bookings/'.$booking['id'], ['agreed_price_net' => '300.00'])->assertOk();
    expect($updated->json('data.hotel_concierge_commission_amount'))->toBe('30.00')
        ->and($updated->json('data.total_distance_km'))->toBe($booking['total_distance_km']);

    $moved = $this->putJson('/api/v1/passenger-transport/bookings/'.$booking['id'], ['dropoff_lat' => 45.0, 'dropoff_lng' => 9.0, 'dropoff_country_code' => 'IT', 'border_crossing_point' => 'none'])->assertOk();
    expect($moved->json('data.ch_territory_km'))->toBe('0.00');
});

it('moves a booking through its lifecycle and refuses the steps its status does not allow', function (): void {
    $booking = ptrBooking($this);
    $url = '/api/v1/passenger-transport/bookings/'.$booking['id'];

    $this->postJson($url.'/transition', ['action' => 'board'])->assertStatus(409)->assertJsonPath('code', 'booking_transition_refused');
    $this->postJson($url.'/transition', ['action' => 'confirm'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->deleteJson($url)->assertStatus(409)->assertJsonPath('code', 'booking_delete_draft_only');
    $this->postJson($url.'/transition', ['action' => 'cancel'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->putJson($url, ['agreed_price_net' => '10'])->assertStatus(409)->assertJsonPath('code', 'booking_locked');
});

it('deletes a draft booking', function (): void {
    $booking = ptrBooking($this);

    $this->deleteJson('/api/v1/passenger-transport/bookings/'.$booking['id'])->assertOk();
    $this->getJson('/api/v1/passenger-transport/bookings/'.$booking['id'])->assertNotFound();
});

it('does not show a booking to another company', function (): void {
    $booking = ptrBooking($this);
    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson('/api/v1/passenger-transport/bookings/'.$booking['id'])->assertNotFound();
    $this->getJson('/api/v1/passenger-transport/bookings')->assertOk()->assertJsonCount(0, 'data');
});

it('serves the lookups with the fiscal context of the company', function (): void {
    $response = $this->getJson('/api/v1/passenger-transport/lookups')->assertOk();

    expect($response->json('data.jurisdiction'))->toBe('IT')
        ->and($response->json('data.service_types'))->toContain('airport_transfer', 'school_bus_route')
        ->and(array_column($response->json('data.border_crossings'), 'value'))->toContain('chiasso_brogeda', 'gran_san_bernardo')
        ->and(array_column($response->json('data.airports'), 'iata'))->toBe(['MXP', 'LIN', 'BGY', 'ZRH', 'GVA', 'LUG']);
});

it('serves the dispatch timeline with the gauges of each driver', function (): void {
    $vehicle = ptrVehicle($this, 'TI 1');
    $driver = ptrDriver($this, false, ['daily_driving_minutes_used' => 120]);
    ptrBooking($this);

    $response = $this->getJson('/api/v1/passenger-transport/dispatch/timeline?from='.now()->toDateString().'&to='.now()->addDays(5)->toDateString())->assertOk();

    expect(array_column($response->json('data.vehicles'), 'id'))->toContain($vehicle['id'])
        ->and($response->json('data.unassigned'))->toHaveCount(1)
        ->and($response->json('data.drivers.0.id'))->toBe($driver['driver_employee_id'])
        ->and($response->json('data.drivers.0.regime'))->toBe('olr2')
        ->and($response->json('data.drivers.0.gauges.daily_used_minutes'))->toBe(120)
        ->and($response->json('data.drivers.0.gauges.remaining_minutes'))->toBe(420);
});
