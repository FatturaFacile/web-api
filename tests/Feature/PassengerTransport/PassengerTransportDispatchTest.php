<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/PassengerTransportSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ptrSetUp($this);
});

/**
 * @return list<string> the codes of the findings of an assignment answer
 */
function ptrIssueCodes(mixed $response, string $path): array
{
    return array_column($response->json($path) ?? [], 'code');
}

it('blocks the assignment when the CQC Persone expires before the trip, with the legal citation', function (): void {
    $coach = ptrVehicle($this, 'TI 10', 'gt_coach_over_3_5t');
    $driver = ptrDriver($this, true, ['cqc_persone_expiry_date' => now()->addDay()->toDateString()]);
    $booking = ptrBooking($this, ['service_type' => 'multi_day_tour_coach', 'pickup_country_code' => 'IT', 'dropoff_country_code' => 'IT', 'border_crossing_point' => 'none', 'pickup_lat' => null, 'pickup_lng' => null, 'dropoff_lat' => null, 'dropoff_lng' => null, 'total_distance_km' => 100, 'passengers_count' => 30]);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $coach['id'], 'driver_employee_id' => $driver['driver_employee_id']]);

    $response->assertStatus(422)->assertJsonPath('code', 'assignment_blocked');
    expect(ptrIssueCodes($response, 'params.issues'))->toContain('cqc_expired')
        ->and(collect($response->json('params.issues'))->firstWhere('code', 'cqc_expired')['citation'])->toContain('286/2005');

    $this->putJson('/api/v1/passenger-transport/drivers/'.$driver['id'], ['cqc_persone_expiry_date' => now()->addYears(2)->toDateString()])->assertOk();

    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $coach['id'], 'driver_employee_id' => $driver['driver_employee_id']])
        ->assertOk()
        ->assertJsonPath('data.booking.status', 'assigned')
        ->assertJsonPath('data.booking.assigned_vehicle_id', $coach['id'])
        ->assertJsonPath('data.compliance.can_assign', true)
        ->assertJsonPath('data.compliance.regime', 'olr1_ec561');
});

it('blocks a licence that expires before the trip even though it is valid today', function (): void {
    $car = ptrVehicle($this, 'TI 11');
    $driver = ptrDriver($this, false, ['driving_license_expiry_date' => now()->addDay()->toDateString()]);
    $booking = ptrBooking($this);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $driver['driver_employee_id']])->assertStatus(422);

    expect(ptrIssueCodes($response, 'params.issues'))->toContain('license_expired');
});

it('blocks a coach trip that touches Switzerland when the CZV has expired', function (): void {
    $coach = ptrVehicle($this, 'TI 12', 'gt_coach_over_3_5t');
    $driver = ptrDriver($this, true, ['czv_expiry_date' => now()->subMonth()->toDateString()]);
    $booking = ptrBooking($this, ['passengers_count' => 30]);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $coach['id'], 'driver_employee_id' => $driver['driver_employee_id']])->assertStatus(422);

    expect(ptrIssueCodes($response, 'params.issues'))->toContain('czv_expired');
});

it('blocks a vehicle too small for the passengers', function (): void {
    $car = ptrVehicle($this, 'TI 13');
    $driver = ptrDriver($this);
    $booking = ptrBooking($this, ['passengers_count' => 6]);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $driver['driver_employee_id']])->assertStatus(422);

    expect(ptrIssueCodes($response, 'params.issues'))->toContain('vehicle_capacity_exceeded');
});

it('only reports the findings on a dry run and assigns nothing', function (): void {
    $car = ptrVehicle($this, 'TI 14');
    $driver = ptrDriver($this);
    $booking = ptrBooking($this);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $driver['driver_employee_id'], 'dry_run' => true])
        ->assertOk()
        ->assertJsonPath('data.compliance.can_assign', true)
        ->assertJsonPath('data.booking.assigned_vehicle_id', null)
        ->assertJsonPath('data.booking.status', 'draft');
});

it('asks to acknowledge the warnings before assigning', function (): void {
    $coach = ptrVehicle($this, 'TI 15', 'gt_coach_over_3_5t');
    $driver = ptrDriver($this, true, ['last_daily_rest_duration_minutes' => 560, 'reduced_9h_rests_used_between_weekly_rests' => 1]);
    $booking = ptrBooking($this, ['passengers_count' => 30, 'pickup_lat' => null, 'pickup_lng' => null, 'dropoff_lat' => null, 'dropoff_lng' => null, 'total_distance_km' => 100]);
    $payload = ['vehicle_id' => $coach['id'], 'driver_employee_id' => $driver['driver_employee_id']];

    $refused = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', $payload)->assertStatus(409)->assertJsonPath('code', 'assignment_warnings_unacknowledged');
    expect(ptrIssueCodes($refused, 'params.issues'))->toContain('reduced_rest_used');

    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', [...$payload, 'acknowledge_warnings' => true])
        ->assertOk()
        ->assertJsonPath('data.compliance.warning_count', 1);
});

it('refuses a vehicle and a driver already committed to an overlapping trip', function (): void {
    $car = ptrVehicle($this, 'TI 16');
    $first = ptrDriver($this);
    $second = ptrDriver($this);
    $a = ptrBooking($this);
    $b = ptrBooking($this);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$a['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $first['driver_employee_id']])->assertOk();

    $vehicleClash = $this->postJson('/api/v1/passenger-transport/bookings/'.$b['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $second['driver_employee_id']])->assertStatus(422);
    expect(ptrIssueCodes($vehicleClash, 'params.issues'))->toContain('vehicle_overlap');

    $other = ptrVehicle($this, 'TI 17');
    $driverClash = $this->postJson('/api/v1/passenger-transport/bookings/'.$b['id'].'/assign', ['vehicle_id' => $other['id'], 'driver_employee_id' => $first['driver_employee_id']])->assertStatus(422);
    expect(ptrIssueCodes($driverClash, 'params.issues'))->toContain('driver_overlap');
});

it('adds the trips already assigned the same day to the daily driving of the driver', function (): void {
    $coach = ptrVehicle($this, 'TI 18', 'gt_coach_over_3_5t');
    $second = ptrVehicle($this, 'TI 19', 'gt_coach_over_3_5t');
    $driver = ptrDriver($this, true);
    $day = now()->addDays(3);
    $long = ['passengers_count' => 30, 'pickup_lat' => null, 'pickup_lng' => null, 'dropoff_lat' => null, 'dropoff_lng' => null, 'border_crossing_point' => 'none', 'pickup_country_code' => 'IT', 'dropoff_country_code' => 'IT', 'total_distance_km' => 300];

    $morning = ptrBooking($this, [...$long, 'pickup_datetime' => $day->copy()->setTime(5, 0)->toDateTimeString()]);
    $evening = ptrBooking($this, [...$long, 'pickup_datetime' => $day->copy()->setTime(15, 0)->toDateTimeString()]);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$morning['id'].'/assign', ['vehicle_id' => $coach['id'], 'driver_employee_id' => $driver['driver_employee_id'], 'planned_break_minutes' => 90, 'acknowledge_warnings' => true])->assertOk();

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$evening['id'].'/assign', ['vehicle_id' => $second['id'], 'driver_employee_id' => $driver['driver_employee_id'], 'planned_break_minutes' => 90])->assertStatus(422);

    expect(ptrIssueCodes($response, 'params.issues'))->toContain('daily_driving_exceeded');
});

it('tracks a delayed flight, moves the pickup and notifies the dispatchers', function (): void {
    $dispatcher = User::create(['name' => 'Dispatcher', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $dispatcher->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'dispatcher', 'permissions' => json_encode([])]);

    $arrival = CarbonImmutable::now()->addHours(3)->startOfMinute();
    $booking = ptrBooking($this, [
        'flight_or_train_number' => 'LH1234', 'flight_carrier_iata' => 'LH', 'arrival_airport_iata' => 'MXP',
        'scheduled_flight_arrival_at' => $arrival->toDateTimeString(), 'pickup_datetime' => $arrival->addMinutes(30)->toDateTimeString(),
    ]);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/track-flight')->assertOk();

    expect($response->json('data.flight.adjusted'))->toBeTrue()
        ->and($response->json('data.flight.delay_minutes'))->toBe(45)
        ->and($response->json('data.booking.flight_status'))->toBe('delayed')
        ->and(CarbonImmutable::parse($response->json('data.booking.pickup_datetime'))->equalTo($arrival->addMinutes(75)))->toBeTrue()
        ->and(CarbonImmutable::parse($response->json('data.booking.estimated_flight_arrival_at'))->equalTo($arrival->addMinutes(45)))->toBeTrue()
        ->and($dispatcher->notifications()->count())->toBe(1)
        ->and($dispatcher->notifications()->first()->data['key'])->toBe('pickup_moved');
});

it('leaves the pickup alone when the flight is on time', function (): void {
    $arrival = CarbonImmutable::now()->addHours(3)->startOfMinute();
    $booking = ptrBooking($this, [
        'flight_or_train_number' => 'LH1237', 'flight_carrier_iata' => 'LH', 'arrival_airport_iata' => 'MXP',
        'scheduled_flight_arrival_at' => $arrival->toDateTimeString(), 'pickup_datetime' => $arrival->addMinutes(30)->toDateTimeString(),
    ]);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/track-flight')->assertOk();

    expect($response->json('data.flight.adjusted'))->toBeFalse()
        ->and($response->json('data.booking.flight_status'))->toBe('scheduled')
        ->and(CarbonImmutable::parse($response->json('data.booking.pickup_datetime'))->equalTo($arrival->addMinutes(30)))->toBeTrue();
});

it('detects the next trip of the same vehicle that a long delay collides with', function (): void {
    $car = ptrVehicle($this, 'TI 20');
    $first = ptrDriver($this);
    $second = ptrDriver($this);
    $arrival = CarbonImmutable::now()->addHours(3)->startOfMinute();

    $a = ptrBooking($this, [
        'flight_or_train_number' => 'LH1236', 'flight_carrier_iata' => 'LH', 'arrival_airport_iata' => 'MXP',
        'scheduled_flight_arrival_at' => $arrival->toDateTimeString(), 'pickup_datetime' => $arrival->addMinutes(30)->toDateTimeString(),
    ]);
    $b = ptrBooking($this, ['pickup_datetime' => $arrival->addMinutes(30)->addHours(3)->toDateTimeString()]);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$a['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $first['driver_employee_id']])->assertOk();
    $this->postJson('/api/v1/passenger-transport/bookings/'.$b['id'].'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $second['driver_employee_id']])->assertOk();

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$a['id'].'/track-flight')->assertOk();

    expect($response->json('data.flight.adjusted'))->toBeTrue()
        ->and(array_column($response->json('data.flight.conflicts'), 'code'))->toContain('vehicle_overlap');
});

it('refuses to track a booking that has no flight', function (): void {
    $booking = ptrBooking($this);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/track-flight')->assertUnprocessable()->assertJsonPath('code', 'booking_without_flight');
});

it('adds the driving of a completed trip to the counters of the driver', function (): void {
    $car = ptrVehicle($this, 'TI 21');
    $driver = ptrDriver($this);
    $booking = ptrBooking($this);
    $url = '/api/v1/passenger-transport/bookings/'.$booking['id'];

    $this->postJson($url.'/assign', ['vehicle_id' => $car['id'], 'driver_employee_id' => $driver['driver_employee_id']])->assertOk();

    foreach (['start_run', 'arrive', 'board', 'complete'] as $action) {
        $this->postJson($url.'/transition', ['action' => $action])->assertOk();
    }

    $record = collect($this->getJson('/api/v1/passenger-transport/drivers')->json('data'))->firstWhere('id', $driver['id']);

    expect($record['daily_driving_minutes_used'])->toBeGreaterThan(0)
        ->and($record['weekly_driving_minutes_used'])->toBe($record['daily_driving_minutes_used']);
});
