<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/PassengerTransportSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ptrSetUp($this);
    Storage::fake((string) config('passenger_transport.storage_disk'));
});

/**
 * A booking assigned to a car and a driver.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{booking: array<string, mixed>, vehicle: array<string, mixed>}
 */
function ptrAssignedBooking(object $test, array $overrides = []): array
{
    $vehicle = ptrVehicle($test, 'TI '.random_int(100, 999));
    $driver = ptrDriver($test);
    $booking = ptrBooking($test, $overrides);
    $test->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $vehicle['id'], 'driver_employee_id' => $driver['driver_employee_id']])->assertOk();

    return ['booking' => $booking, 'vehicle' => $vehicle];
}

/**
 * @return array<string, mixed>
 */
function ptrSheetTimes(): array
{
    $day = now()->addDays(2)->setTime(0, 0);

    return [
        'garage_departure_at' => $day->copy()->setTime(13, 50)->toDateTimeString(), 'garage_departure_km' => 48200,
        'service_start_at' => $day->copy()->setTime(14, 30)->toDateTimeString(), 'service_start_km' => 48230,
        'service_end_at' => $day->copy()->setTime(15, 45)->toDateTimeString(), 'service_end_km' => 48290,
        'garage_return_at' => $day->copy()->setTime(16, 20)->toDateTimeString(), 'garage_return_km' => 48320,
    ];
}

it('opens the service sheet of an assigned booking with the progressive number of the year', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertCreated();

    expect($response->json('data.number'))->toBe(sprintf('FDS-%d-000001', now()->addDays(2)->year))
        ->and($response->json('data.status'))->toBe('open')
        ->and($response->json('data.interbus_eu_waybill_number'))->toBeNull();

    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertStatus(409)->assertJsonPath('code', 'sheet_already_open');
});

it('numbers the sheets of the company one after the other', function (): void {
    ['booking' => $first] = ptrAssignedBooking($this);
    ['booking' => $second] = ptrAssignedBooking($this);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$first['id'].'/service-sheets')->assertCreated()->assertJsonPath('data.progressive_sheet_number', 1);
    $this->postJson('/api/v1/passenger-transport/bookings/'.$second['id'].'/service-sheets')->assertCreated()->assertJsonPath('data.progressive_sheet_number', 2);
});

it('refuses a sheet for a booking that is not assigned', function (): void {
    $booking = ptrBooking($this);

    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertUnprocessable()->assertJsonPath('code', 'sheet_requires_assignment');
});

it('refuses to close a sheet without the mandatory pre-departure content of the Italian rules', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this);
    $sheet = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertCreated()->json('data');

    $response = $this->postJson('/api/v1/passenger-transport/service-sheets/'.$sheet['id'].'/close')->assertUnprocessable();

    expect($response->json('code'))->toBe('sheet_incomplete')
        ->and($response->json('params.missing'))->toBe(['garage_departure_at']);
});

it('refuses kilometres that go down between the readings', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this);
    $sheet = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets', [...ptrSheetTimes(), 'service_end_km' => 48000])->assertCreated()->json('data');

    $this->postJson('/api/v1/passenger-transport/service-sheets/'.$sheet['id'].'/close')->assertUnprocessable()->assertJsonPath('code', 'sheet_kilometres_decreasing');
});

it('closes the sheet with its signature and PDF, transmits it and updates the odometer of the vehicle', function (): void {
    ['booking' => $booking, 'vehicle' => $vehicle] = ptrAssignedBooking($this);
    $sheet = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertCreated()->json('data');
    $url = '/api/v1/passenger-transport/service-sheets/'.$sheet['id'];

    $this->putJson($url, ptrSheetTimes())->assertOk()->assertJsonPath('data.garage_return_km', 48320);

    $closed = $this->postJson($url.'/close')->assertOk();
    expect($closed->json('data.status'))->toBe('closed')
        ->and(strlen((string) $closed->json('data.digital_signature_hash')))->toBe(64)
        ->and($closed->json('data.has_pdf'))->toBeTrue();

    $this->putJson($url, ['garage_return_km' => 50000])->assertStatus(409)->assertJsonPath('code', 'sheet_locked');
    $this->postJson($url.'/close')->assertStatus(409);

    $transmitted = $this->postJson($url.'/transmit')->assertOk();
    expect($transmitted->json('data.status'))->toBe('transmitted')
        ->and($transmitted->json('data.mit_fdse_protocol_code_it'))->toMatch('/^FDSE-[A-F0-9]{16}$/')
        ->and($transmitted->json('data.mit_fdse_transmitted_at'))->not->toBeNull();

    $vehicles = collect($this->getJson('/api/v1/passenger-transport/vehicles')->json('data'));
    expect($vehicles->firstWhere('id', $vehicle['id'])['odometer_km'])->toBe(48320);

    $pdf = $this->get($url.'/pdf')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

it('does not transmit a sheet that is still open', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this);
    $sheet = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertCreated()->json('data');

    $this->postJson('/api/v1/passenger-transport/service-sheets/'.$sheet['id'].'/transmit')->assertStatus(409)->assertJsonPath('code', 'sheet_not_closed');
});

it('issues the Interbus waybill with the passenger manifest for an international coach tour', function (): void {
    $coach = ptrVehicle($this, 'TI 500', 'gt_coach_over_3_5t');
    $driver = ptrDriver($this, true);
    $booking = ptrBooking($this, ['service_type' => 'multi_day_tour_coach', 'passengers_count' => 30]);
    $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/assign', ['vehicle_id' => $coach['id'], 'driver_employee_id' => $driver['driver_employee_id']])->assertOk();

    $sheet = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets', [
        'passenger_manifest' => [['name' => 'Anna Bianchi', 'birth_date' => '1980-04-12', 'document' => 'CA1234567', 'nationality' => 'IT'], ['name' => 'Hans Muster', 'birth_date' => '1975-09-30', 'document' => 'X7654321', 'nationality' => 'CH']],
    ])->assertCreated();

    expect($sheet->json('data.interbus_eu_waybill_number'))->toBe(sprintf('IB-%d-000001', now()->addDays(2)->year))
        ->and($sheet->json('data.passenger_manifest'))->toHaveCount(2);

    $pdf = $this->get('/api/v1/passenger-transport/service-sheets/'.$sheet->json('data.id').'/interbus-pdf')->assertOk();
    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

it('has no Interbus waybill for a transfer', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this);
    $sheet = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets')->assertCreated()->json('data');

    $this->get('/api/v1/passenger-transport/service-sheets/'.$sheet['id'].'/interbus-pdf', ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'sheet_not_international_coach');
});

it('generates the sheet, transmits it and invoices a completed trip with the VAT split in one call', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this, ['currency' => 'EUR']);
    $url = '/api/v1/passenger-transport/bookings/'.$booking['id'];

    foreach (['start_run', 'arrive', 'board', 'complete'] as $action) {
        $this->postJson($url.'/transition', ['action' => $action])->assertOk();
    }

    $response = $this->postJson($url.'/service-sheets/generate', [...ptrSheetTimes(), 'close' => true, 'transmit' => true, 'invoice' => true])->assertOk();

    expect($response->json('data.sheet.status'))->toBe('transmitted')
        ->and($response->json('data.invoice.id'))->not->toBeNull()
        ->and($this->getJson($url)->json('data.status'))->toBe('invoiced');
});

it('generates only the sheet when the invoice is not asked', function (): void {
    ['booking' => $booking] = ptrAssignedBooking($this);

    $response = $this->postJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/service-sheets/generate', [...ptrSheetTimes(), 'close' => true])->assertOk();

    expect($response->json('data.sheet.status'))->toBe('closed')
        ->and($response->json('data.invoice'))->toBeNull();
});

it('serves the welcome sign with the logo of the hotel and as a landscape PDF', function (): void {
    $booking = ptrBooking($this, ['lead_passenger_name' => 'Mr. Weber', 'vip_welcome_sign_text' => 'Mr. & Mrs. Weber']);

    $this->post('/api/v1/passenger-transport/bookings/'.$booking['id'].'/welcome-logo', ['logo' => UploadedFile::fake()->image('hotel.png', 200, 80)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_welcome_logo', true);

    $sign = $this->getJson('/api/v1/passenger-transport/bookings/'.$booking['id'].'/welcome-sign')->assertOk();
    expect($sign->json('data.text'))->toBe('Mr. & Mrs. Weber')
        ->and($sign->json('data.logo_data_uri'))->toStartWith('data:image/png;base64,');

    $pdf = $this->get('/api/v1/passenger-transport/bookings/'.$booking['id'].'/welcome-sign/pdf')->assertOk();
    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();
});

it('refuses a welcome logo that is not an image', function (): void {
    $booking = ptrBooking($this);

    $this->post('/api/v1/passenger-transport/bookings/'.$booking['id'].'/welcome-logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
});
