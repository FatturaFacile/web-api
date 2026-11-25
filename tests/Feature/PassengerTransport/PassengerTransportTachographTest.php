<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\PassengerTransport\Models\DddArchive;

require_once __DIR__.'/PassengerTransportSupport.php';
require_once __DIR__.'/../../Unit/PassengerTransport/DddFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ptrSetUp($this);
    Storage::fake((string) config('passenger_transport.storage_disk'));
});

/**
 * Two days of a driver card: 680 minutes on the first (daily and continuous driving infringements), 360 on the second.
 */
function ptrCardBinary(string $cardNumber = '1000012345678901'): string
{
    return ptrDriverCardFile([
        '2028-03-06' => [[PTR_REST, 0], [PTR_DRIVING, 360], [PTR_REST, 720], [PTR_DRIVING, 780], [PTR_REST, 1100]],
        '2028-03-07' => [[PTR_REST, 0], [PTR_DRIVING, 480], [PTR_REST, 600], [PTR_DRIVING, 660], [PTR_REST, 900]],
    ], $cardNumber);
}

function ptrUpload(object $test, string $binary, array $fields = [], string $name = 'driver.ddd'): TestResponse
{
    return $test->post('/api/v1/passenger-transport/tachograph/archives', ['file' => UploadedFile::fake()->createWithContent($name, $binary), ...$fields], ['Accept' => 'application/json']);
}

it('archives a driver card file, records its infringements and refreshes the counters of the driver', function (): void {
    $driver = ptrDriver($this, true, ['tachograph_card_number' => '1000012345678901']);

    $response = ptrUpload($this, ptrCardBinary())->assertCreated();

    expect($response->json('data.file_type'))->toBe('driver_card')
        ->and($response->json('data.card_number'))->toBe('1000012345678901')
        ->and($response->json('data.period_start'))->toBe('2028-03-06')
        ->and($response->json('data.period_end'))->toBe('2028-03-07')
        ->and($response->json('data.infringements_count'))->toBe(2)
        ->and($response->json('data.driver_employee_id'))->toBe($driver['driver_employee_id'])
        ->and(strlen((string) $response->json('data.sha256')))->toBe(64);

    $record = collect($this->getJson('/api/v1/passenger-transport/drivers')->json('data'))->firstWhere('id', $driver['id']);

    expect($record['daily_driving_minutes_used'])->toBe(360)
        ->and($record['weekly_driving_minutes_used'])->toBe(1040)
        ->and($record['biweekly_driving_minutes_used'])->toBe(1040)
        ->and($record['extended_10h_days_used_this_week'])->toBe(1)
        ->and($record['last_daily_rest_duration_minutes'])->toBe(540)
        ->and($record['tachograph_card_expiry_date'])->toBe('2029-01-01')
        ->and($record['available_for_next_trip'])->toBeTrue();

    $infringements = $this->getJson('/api/v1/passenger-transport/tachograph/infringements?driver_employee_id='.$driver['driver_employee_id'])->assertOk();
    expect(array_column($infringements->json('data'), 'code'))->toBe(['daily_driving_exceeded', 'continuous_driving_exceeded']);
});

it('stores the file in the archive and gives it back unchanged', function (): void {
    ptrDriver($this, true, ['tachograph_card_number' => '1000012345678901']);
    $binary = ptrCardBinary();

    $archive = ptrUpload($this, $binary)->assertCreated()->json('data');
    $stored = DddArchive::query()->findOrFail($archive['id']);

    expect(Storage::disk((string) config('passenger_transport.storage_disk'))->get($stored->storage_path))->toBe($binary)
        ->and($stored->sha256)->toBe(hash('sha256', $binary));

    $this->get('/api/v1/passenger-transport/tachograph/archives/'.$archive['id'].'/download')->assertOk()->assertDownload('driver.ddd');
});

it('refuses the same file twice by its SHA-256', function (): void {
    ptrDriver($this, true, ['tachograph_card_number' => '1000012345678901']);

    ptrUpload($this, ptrCardBinary())->assertCreated();
    ptrUpload($this, ptrCardBinary(), [], 'again.ddd')->assertStatus(409)->assertJsonPath('code', 'ddd_duplicate');
});

it('asks for the driver when no card matches, and refuses a card that is not the driver card', function (): void {
    $driver = ptrDriver($this, true, ['tachograph_card_number' => '1000099999999999']);

    ptrUpload($this, ptrCardBinary())->assertUnprocessable()->assertJsonPath('code', 'ddd_driver_unresolved');
    ptrUpload($this, ptrCardBinary(), ['driver_employee_id' => $driver['driver_employee_id']])->assertUnprocessable()->assertJsonPath('code', 'ddd_card_mismatch');
});

it('archives a vehicle unit file with its hash without reading the activities', function (): void {
    $vehicle = ptrVehicle($this, 'TI 30', 'gt_coach_over_3_5t');

    $response = ptrUpload($this, "\x76\x01".random_bytes(64), ['vehicle_id' => $vehicle['id']], 'vehicle.ddd')->assertCreated();

    expect($response->json('data.file_type'))->toBe('vehicle_unit')
        ->and($response->json('data.driver_employee_id'))->toBeNull()
        ->and($response->json('data.infringements_count'))->toBe(0)
        ->and($response->json('data.vehicle_id'))->toBe($vehicle['id']);
});

it('refuses a file that is not a tachograph download', function (): void {
    ptrUpload($this, 'not a tachograph file at all')->assertUnprocessable()->assertJsonPath('code', 'ddd_file_unrecognised');
    ptrUpload($this, 'abc')->assertUnprocessable()->assertJsonPath('code', 'ddd_file_invalid');
    $this->post('/api/v1/passenger-transport/tachograph/archives', ['file' => UploadedFile::fake()->create('notes.txt', 1)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
});

it('lists the archive of a driver with its history', function (): void {
    $driver = ptrDriver($this, true, ['tachograph_card_number' => '1000012345678901']);
    ptrUpload($this, ptrCardBinary())->assertCreated();

    $list = $this->getJson('/api/v1/passenger-transport/tachograph/archives?driver_employee_id='.$driver['driver_employee_id'])->assertOk();

    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.infringements_summary.0.code'))->toBe('daily_driving_exceeded');
});

it('keeps the driver records of the company: one per employee, licence categories checked', function (): void {
    $driver = ptrDriver($this);

    $this->postJson('/api/v1/passenger-transport/drivers', ['driver_employee_id' => $driver['driver_employee_id']])->assertStatus(409)->assertJsonPath('code', 'driver_record_exists');
    $this->putJson('/api/v1/passenger-transport/drivers/'.$driver['id'], ['driving_license_categories' => ['Z']])->assertUnprocessable();
    $this->postJson('/api/v1/passenger-transport/drivers', ['driver_employee_id' => ptrEmployee($this->otherTenant, 'Foreign')->id])->assertUnprocessable()->assertJsonValidationErrors('driver_employee_id');
});
